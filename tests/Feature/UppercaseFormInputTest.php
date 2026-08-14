<?php

namespace Tests\Feature;

use App\Http\Middleware\UppercaseFormInput;
use Illuminate\Http\Request;
use Tests\TestCase;

class UppercaseFormInputTest extends TestCase
{
    public function test_it_uppercases_form_text_without_touching_sensitive_or_structural_fields(): void
    {
        $request = Request::create('/prestamos', 'POST', [
            'first_name' => 'Maria Gabriela',
            'last_name' => 'Daza Gamboa',
            'brand' => 'Mazda',
            'model' => 'cx-30',
            'plates' => 'abc-123',
            'vin' => '3MVDMBBM1NM123456',
            'note' => 'Factura en caja',
            'email' => 'Maria.Gaby@example.com',
            'password' => 'MiClave2026$',
            'rate_type' => 'monthly',
            'invoice_holder' => 'Recepcion',
            'start_date' => '2026-08-14',
            'capital' => '99000',
            'permissions' => ['loans.formalize'],
            'investors' => [
                ['investor_id' => '7', 'capital_amount' => '50000', 'note' => 'socio sin capital extra'],
            ],
        ]);

        (new UppercaseFormInput())->handle($request, fn () => response('ok'));

        $this->assertSame('MARIA GABRIELA', $request->input('first_name'));
        $this->assertSame('DAZA GAMBOA', $request->input('last_name'));
        $this->assertSame('MAZDA', $request->input('brand'));
        $this->assertSame('CX-30', $request->input('model'));
        $this->assertSame('ABC-123', $request->input('plates'));
        $this->assertSame('3MVDMBBM1NM123456', $request->input('vin'));
        $this->assertSame('FACTURA EN CAJA', $request->input('note'));
        $this->assertSame('SOCIO SIN CAPITAL EXTRA', $request->input('investors.0.note'));

        $this->assertSame('Maria.Gaby@example.com', $request->input('email'));
        $this->assertSame('MiClave2026$', $request->input('password'));
        $this->assertSame('monthly', $request->input('rate_type'));
        $this->assertSame('Recepcion', $request->input('invoice_holder'));
        $this->assertSame('2026-08-14', $request->input('start_date'));
        $this->assertSame('99000', $request->input('capital'));
        $this->assertSame(['loans.formalize'], $request->input('permissions'));
        $this->assertSame('7', $request->input('investors.0.investor_id'));
        $this->assertSame('50000', $request->input('investors.0.capital_amount'));
    }
}
