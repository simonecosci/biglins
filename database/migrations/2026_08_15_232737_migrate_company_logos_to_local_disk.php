<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('companies')
            ->whereNotNull('logo')
            ->where('logo', 'like', 'images/companies/%')
            ->get()
            ->each(function (object $company): void {
                $oldPath = public_path($company->logo);
                $filename = basename($company->logo);
                $newPath = 'companies/'.$filename;

                if (File::exists($oldPath)) {
                    Storage::disk('local')->put($newPath, File::get($oldPath));
                    File::delete($oldPath);
                }

                DB::table('companies')->where('id', $company->id)->update(['logo' => $newPath]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally a no-op: this migrates historical data (logos written by
        // the old public_path()-based upload code) forward to match the new
        // Storage::disk('local')-based code. There's no way to distinguish a
        // company row this migration touched from one a user uploaded normally
        // after the code changed (both end up in the same `companies/{id}.ext`
        // format), so a real reversal risks corrupting unrelated, perfectly
        // fine logos. This migration is one-way.
    }
};
