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
        DB::table('companies')
            ->whereNotNull('logo')
            ->where('logo', 'like', 'companies/%')
            ->get()
            ->each(function (object $company): void {
                $filename = basename($company->logo);
                $oldPath = 'images/companies/'.$filename;

                if (Storage::disk('local')->exists($company->logo)) {
                    File::ensureDirectoryExists(public_path('images/companies'));
                    File::put(public_path($oldPath), Storage::disk('local')->get($company->logo));
                    Storage::disk('local')->delete($company->logo);
                }

                DB::table('companies')->where('id', $company->id)->update(['logo' => $oldPath]);
            });
    }
};
