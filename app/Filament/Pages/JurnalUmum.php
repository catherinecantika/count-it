<?php

namespace App\Filament\Pages;

use App\Services\JurnalService;
use App\Filament\Resources\JurnalUmumResource;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Carbon\Carbon;
use Livewire\Component;
use Barryvdh\DomPDF\Facade\Pdf;

use App\Models\Account;
use App\Models\Income;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\BillOfMaterialItem;
use App\Models\Supplies;

class JurnalUmum extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $title = 'Jurnal Umum';
    protected static ?string $navigationGroup = 'Laporan';
    protected static ?string $navigationLabel = 'Jurnal Umum';

    public function downloadPDF($bulan)
    {
        $records = $this->getRecordsProperty();  
        $pdf = PDF::loadView('filament.pages.jurnal-pdf', compact('records', 'bulan'));
        return $pdf->download('jurnalumum_' . $bulan . '.pdf');
    }

    public function getAccount(string $name)
    {
        return Account::where('name_account', $name)->firstOr(function () use ($name) {
            return (object) [
                'code_account' => '-',
                'name_account' => $name
            ];
        });
    }

    public $bulan;

    public function mount()
    {
        $this->bulan = null;
    }

    public function getRecordsProperty()
    {
        $bulan = Carbon::parse($this->bulan);
        $data = collect();

        $akunKas = $this->getAccount('Kas');
        $akunPersediaan = $this->getAccount('Persediaan Bahan Baku');
        $akunPendapatan = $this->getAccount('Penjualan');
        $akunPemakaian = $this->getAccount('Pemakaian Bahan Baku');
        $akunBarangDalamProses = $this->getAccount('Persediaan Barang Dalam Proses');

        $incomes = Income::with('category.account')
            ->whereMonth('date_income', $bulan->month)
            ->whereYear('date_income', $bulan->year)
            ->get()
            ->map(function ($item) use ($akunKas) {
                $akunKategori = $item->category && $item->category->account
                    ? [
                        'code' => $item->category->account->code_account,
                        'name' => $item->category->account->name_account,
                    ] : [
                        'code' => '-',
                        'name' => 'Akun Tidak Ditemukan',
                    ];

                return [
                    'date' => $item->date_income,
                    'code' => $item->code_income,
                    'entries' => [
                        ['account' => ['code' => $akunKas->code_account, 'name' => $akunKas->name_account], 'debit' => (float) $item->amount_income, 'credit' => 0],
                        ['account' => $akunKategori, 'debit' => 0, 'credit' => (float) $item->amount_income],
                    ],
                ];
            });

        $expenses = Expense::with('category.account')
            ->whereMonth('date_expense', $bulan->month)
            ->whereYear('date_expense', $bulan->year)
            ->get()
            ->map(function ($item) use ($akunKas) {
                $akunKategori = $item->category && $item->category->account
                    ? [
                        'code' => $item->category->account->code_account,
                        'name' => $item->category->account->name_account,
                    ] : [
                        'code' => '-',
                        'name' => 'Akun Tidak Ditemukan',
                    ];

                return [
                    'date' => $item->date_expense,
                    'code' => $item->code_expense,
                    'entries' => [
                        ['account' => $akunKategori, 'debit' => (float) $item->amount_expense, 'credit' => 0],
                        ['account' => ['code' => $akunKas->code_account, 'name' => $akunKas->name_account], 'debit' => 0, 'credit' => (float) $item->amount_expense],
                    ],
                ];
            });

        $purchases = Purchase::with('purchaseItems')
            ->whereMonth('date', $bulan->month)
            ->whereYear('date', $bulan->year)
            ->get()
            ->map(function ($item) use ($akunKas, $akunPersediaan) {
                $total = $item->purchaseItems->sum('price');
                return [
                    'date' => $item->date,
                    'code' => $item->code,
                    'entries' => [
                        ['account' => ['code' => $akunPersediaan->code_account, 'name' => $akunPersediaan->name_account], 'debit' => (float) $total, 'credit' => 0],
                        ['account' => ['code' => $akunKas->code_account, 'name' => $akunKas->name_account], 'debit' => 0, 'credit' => (float) $total],
                    ],
                ];
            });

        $orders = Order::with('orderItem')
            ->whereMonth('created_at', $bulan->month)
            ->whereYear('created_at', $bulan->year)
            ->get()
            ->map(function ($item) use ($akunKas, $akunPendapatan) {
                $total = $item->orderItem->sum('price');
                return [
                    'date' => $item->created_at,
                    'code' => $item->code,
                    'entries' => [
                        ['account' => ['code' => $akunKas->code_account, 'name' => $akunKas->name_account], 'debit' => (float) $total, 'credit' => 0],
                        ['account' => ['code' => $akunPendapatan->code_account, 'name' => $akunPendapatan->name_account], 'debit' => 0, 'credit' => (float) $total],
                    ],
                ];
            });

        $pemakaian = BillOfMaterialItem::join('bill_of_materials', 'bill_of_material_items.bill_of_materials_id', '=', 'bill_of_materials.id')
            ->join('order_items', 'order_items.menus_id', '=', 'bill_of_materials.menus_id')
            ->join('orders', 'orders.id', '=', 'order_items.orders_id')
            ->join('supplies', 'supplies.id', '=', 'bill_of_material_items.supplies_id')
            ->whereMonth('orders.created_at', $bulan->month)
            ->whereYear('orders.created_at', $bulan->year)
            ->select('orders.created_at as tanggal', 'orders.code as code', 'bill_of_material_items.quantity', 'order_items.quantity as order_qty', 'supplies.id as bahan_id')
            ->get()
            ->groupBy('code')
            ->map(function ($groupedItems, $code) use ($akunBarangDalamProses, $akunPersediaan) {
                $total = 0;
                $tanggal = null;
                $hargaDefault = 1000; // asumsi harga satuan default

                foreach ($groupedItems as $item) {
                    $subtotal = $item->quantity * $item->order_qty * $hargaDefault;
                    $total += $subtotal;
                    $tanggal = $item->tanggal;
                }

                return [
                    'date' => $tanggal,
                    'code' => $code . '-BDP',
                    'entries' => [
                        ['account' => ['code' => $akunBarangDalamProses->code_account, 'name' => $akunBarangDalamProses->name_account], 'debit' => (float) $total, 'credit' => 0],
                        ['account' => ['code' => $akunPersediaan->code_account, 'name' => $akunPersediaan->name_account], 'debit' => 0, 'credit' => (float) $total],
                    ],
                ];
            })->values();

        return $data
            ->merge($incomes)
            ->merge($expenses)
            ->merge($purchases)
            ->merge($orders)
            ->merge($pemakaian)
            ->sortBy('date')
            ->values();
    }

    protected function getViewData(): array
    {
        return [
            'records' => $this->records,
            'bulan' => $this->bulan,
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Select::make('bulan')
                ->label('Pilih Bulan')
                ->options($this->generateMonthOptions())
                ->reactive()
                ->afterStateUpdated(fn () => $this->dispatch('refresh')),
        ];
    }

    public function generateMonthOptions(): array
    {
        return collect(range(0, 11))->mapWithKeys(function ($i) {
            $date = now()->subMonths($i);
            return [$date->format('Y-m') => $date->translatedFormat('F Y')];
        })->toArray();
    }

    protected static string $view = 'filament.pages.jurnal-umum';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['pemilik', 'keuangan']);
    }
}
