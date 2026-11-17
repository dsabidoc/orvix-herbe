<span class="badge {{ $statusClass ?? '' }} {{ match ($cut->status) {
    'submitted', 'observed' => 'cash-status-pending',
    'approved_test' => 'cash-status-approved',
    default => '',
} }}">{{ $cut->statusLabel() }}</span>
