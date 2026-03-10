<?php

namespace App\Http\Controllers;

use App\Models\Banco;
use App\Models\BankFormat;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankFormatController extends Controller
{
    // Used for the Management Page
    public function index()
    {
        $formats = BankFormat::orderBy('name')->get();

        return \Inertia\Inertia::render('BankFormats/Index', [
            'formats' => $formats,
        ]);
    }

    // API endpoint for Dropdowns
    public function list()
    {
        return BankFormat::with('banco:id,nombre')->orderBy('name')->get();
    }

    public function create()
    {
        return \Inertia\Inertia::render('BankFormats/Create');
    }

    public function edit(BankFormat $bankFormat)
    {
        return \Inertia\Inertia::render('BankFormats/Create', [
            'format' => $bankFormat,
        ]);
    }

    public function update(Request $request, BankFormat $bankFormat)
    {

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('bank_formats')->ignore($bankFormat->id)->where(function ($query) {
                    return $query->where('team_id', auth()->user()->current_team_id);
                }),
            ],
            // Optional: allow partial updates (just name/color) OR full mapping update
            // Start row, columns are only required if we are re-mapping
            'start_row' => 'nullable|integer|min:1',
            'date_column' => 'nullable|string|max:2',
            'description_column' => 'nullable|string|max:2',
            'amount_column' => 'nullable|string|max:2',
            'debit_column' => 'nullable|string|max:2',
            'credit_column' => 'nullable|string|max:2',
            'reference_column' => 'nullable|string|max:2',
            'type_column' => 'nullable|string|max:2',
            'color' => 'nullable|string|max:20',
        ]);

        $teamId = auth()->user()->current_team_id;

        $banco = Banco::firstOrCreate(
            ['nombre' => $request->name, 'team_id' => $teamId],
            ['codigo' => \Illuminate\Support\Str::slug($request->name).'-t'.$teamId, 'estatus' => 'activo']
        );

        $data = [
            'name' => $request->name,
            'banco_id' => $banco->id,
            'color' => $request->color ?? $bankFormat->color,
        ];

        // Only update mapping fields if provided (meaning a file was re-mapped)
        if ($request->has('date_column')) {
            $data['start_row'] = $request->start_row;
            $data['date_column'] = strtoupper($request->date_column);
            $data['description_column'] = strtoupper($request->description_column);

            // Amount or Debit/Credit
            $data['amount_column'] = $request->amount_column ? strtoupper($request->amount_column) : null;
            $data['debit_column'] = $request->debit_column ? strtoupper($request->debit_column) : null;
            $data['credit_column'] = $request->credit_column ? strtoupper($request->credit_column) : null;

            $data['reference_column'] = $request->reference_column ? strtoupper($request->reference_column) : null;
            $data['type_column'] = $request->type_column ? strtoupper($request->type_column) : null;
        }

        $bankFormat->update($data);

        return redirect()->route('bank-formats.index')->with('success', 'Formato actualizado exitosamente.');
    }

    public function preview(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $rows = [];
        try {
            $path = $request->file('file')->getRealPath();
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();

            // SECURITY: Limit reading to avoid memory exhaustion during preview
            $maxRows = 100;
            $rowCount = 0;
            foreach ($sheet->getRowIterator(1, $maxRows) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $cells = [];
                foreach ($cellIterator as $cell) {
                    $cells[] = $cell->getFormattedValue();
                }
                $rows[] = $cells;
            }
        } catch (\Exception $e) {
            return back()->withErrors(['file' => 'Error reading file: '.$e->getMessage()]);
        }

        return response()->json([
            'rows' => $rows,
            'filename' => $request->file('file')->getClientOriginalName(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('bank_formats')->where(function ($query) {
                    return $query->where('team_id', auth()->user()->current_team_id);
                }),
            ],
            'start_row' => 'required|integer|min:1',
            'date_column' => 'required|string|max:2',
            'description_column' => 'required|string|max:2',
            'amount_column' => 'nullable|string|max:2|required_without_all:debit_column,credit_column',
            'debit_column' => 'nullable|string|max:2|required_without:amount_column',
            'credit_column' => 'nullable|string|max:2|required_without:amount_column',
            'reference_column' => 'nullable|string|max:2',
            'type_column' => 'nullable|string|max:2',
            'color' => 'nullable|string|max:20',
        ]);

        $teamId = auth()->user()->current_team_id;

        $banco = Banco::firstOrCreate(
            ['nombre' => $request->name, 'team_id' => $teamId],
            ['codigo' => \Illuminate\Support\Str::slug($request->name).'-t'.$teamId, 'estatus' => 'activo']
        );

        $format = BankFormat::create([
            'team_id' => $teamId,
            'banco_id' => $banco->id,
            'name' => $request->name,
            'start_row' => $request->start_row,
            'date_column' => strtoupper($request->date_column),
            'description_column' => strtoupper($request->description_column),
            'amount_column' => $request->amount_column ? strtoupper($request->amount_column) : null,
            'debit_column' => $request->debit_column ? strtoupper($request->debit_column) : null,
            'credit_column' => $request->credit_column ? strtoupper($request->credit_column) : null,
            'reference_column' => $request->reference_column ? strtoupper($request->reference_column) : null,
            'type_column' => $request->type_column ? strtoupper($request->type_column) : null,
            'color' => $request->color ?? '#3b82f6',
        ]);

        return redirect()->route('bank-formats.index')->with('success', 'Formato creado exitosamente.');
    }

    public function destroy(BankFormat $bankFormat)
    {
        if ($bankFormat->team_id !== auth()->user()->current_team_id) {
            abort(403);
        }

        $bankFormat->delete();

        return back()->with('success', 'Formato eliminado.');
    }
}
