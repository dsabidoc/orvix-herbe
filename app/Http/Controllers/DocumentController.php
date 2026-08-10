<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Loan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $query = Document::query()
            ->with(['client', 'loan.operator', 'loan.vehicle'])
            ->latest();

        if ($request->user()->hasRole('operador-cartera')) {
            $query->whereHas('loan', fn ($query) => $query->where('operator_id', $request->user()->operatorProfile?->id));
        }

        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(function ($query) use ($search) {
                $query
                    ->where('original_name', 'like', $search)
                    ->orWhere('notes', 'like', $search)
                    ->orWhereHas('client', fn ($query) => $query
                        ->where('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('phone', 'like', $search))
                    ->orWhereHas('loan', fn ($query) => $query->where('folio', 'like', $search))
                    ->orWhereHas('loan.vehicle', fn ($query) => $query
                        ->where('model', 'like', $search)
                        ->orWhere('plates', 'like', $search)
                        ->orWhere('vin', 'like', $search));
            });
        }

        return view('documents.index', [
            'documents' => $query->paginate(20)->withQueryString(),
        ]);
    }

    public function store(Request $request, Loan $loan): RedirectResponse
    {
        $this->authorizeLoanAccess($request, $loan);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'file' => ['required', 'file', 'max:102400'],
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $displayName = $extension && ! str_ends_with(strtolower($data['name']), '.'.strtolower($extension))
            ? $data['name'].'.'.$extension
            : $data['name'];
        $path = $file->store('expedientes/'.$loan->public_id, 'local');

        Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'client_id' => $loan->client_id,
            'uploaded_by' => $request->user()->id,
            'original_name' => $displayName,
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'status' => 'delivered',
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('loans.show', $loan)->with('status', 'Archivo subido al expediente.');
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authorizeDocumentAccess($request, $document);

        $disk = $this->disk($document);

        abort_unless(Storage::disk($disk)->exists($document->path), 404);

        return Storage::disk($disk)->download($document->path, $document->original_name);
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorizeDocumentAccess($request, $document);

        $disk = $this->disk($document);

        if (Storage::disk($disk)->exists($document->path)) {
            Storage::disk($disk)->delete($document->path);
        }

        $document->delete();

        return back()->with('status', 'Archivo eliminado del expediente.');
    }

    private function authorizeLoanAccess(Request $request, Loan $loan): void
    {
        if ($request->user()->hasRole('operador-cartera') && $loan->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }
    }

    private function authorizeDocumentAccess(Request $request, Document $document): void
    {
        $document->loadMissing('loan');

        if ($request->user()->hasRole('operador-cartera') && $document->loan?->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }
    }

    private function disk(Document $document): string
    {
        return $document->disk === 'private' ? 'local' : $document->disk;
    }
}
