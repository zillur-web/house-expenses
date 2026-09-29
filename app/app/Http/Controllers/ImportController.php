<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Services\AccountingService;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function index(Request $r)
    {
        $preview = $r->preview ? DB::table('imports')->where('id', $r->preview)->first() : null;

        return view('imports', ['imports' => DB::table('imports')->latest('id')->get(), 'preview' => $preview, 'members' => Member::where('active', true)->get()]);
    }

    public function preview(Request $r, ImportService $service)
    {
        $r->validate(['file' => 'required|file|mimes:xlsx,csv|max:10240', 'mode' => 'required|in:legacy,transactions,members']);
        $file = $r->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = DB::table('imports')->where('hash', $hash)->first();
        if ($existing) {
            return redirect('/imports?preview='.$existing->id);
        }try {
            $data = $service->preview($file->getRealPath(), $r->mode);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['file' => 'The workbook could not be read. Check its format and required columns.']);
        }$id = DB::table('imports')->insertGetId(['filename' => $file->getClientOriginalName(), 'hash' => $hash, 'rows' => json_encode(['mode' => $r->mode, 'data' => $data['rows']]), 'errors' => json_encode($data['errors']), 'status' => 'preview', 'user_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return redirect('/imports?preview='.$id);
    }

    public function confirm(Request $r, int $id, ImportService $s, AccountingService $a)
    {
        $options = $r->validate(['acknowledge' => 'required|accepted', 'sheet' => 'nullable|string', 'members' => 'nullable|array', 'members.*' => 'required|integer|distinct|exists:members,id']);
        $s->confirm($id, $options, $a);

        return redirect('/imports')->with('success','Import completed successfully.');
    }
}
