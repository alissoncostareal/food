<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Http\Controllers\Controller;
use App\Services\CatalogXmlImporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CatalogImportController extends Controller
{
    use ResolvesMerchantStore;

    public function sample(): BinaryFileResponse
    {
        $path = resource_path('samples/partiumenu-catalog-example.xml');

        abort_unless(is_file($path), 404, 'Arquivo de exemplo não encontrado.');

        return response()->download($path, 'partiumenu-catalog-exemplo.xml', [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function preview(Request $request, CatalogXmlImporter $importer)
    {
        $validated = $this->validateXmlUpload($request);
        $store = $this->merchantStore();

        try {
            $content = file_get_contents($validated['file']->getRealPath());

            return response()->json([
                'preview' => $importer->preview($store, $content),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Não foi possível ler o XML.',
                'details' => $e->getMessage(),
            ], 422);
        }
    }

    public function import(Request $request, CatalogXmlImporter $importer)
    {
        $validated = $this->validateXmlUpload($request);
        $store = $this->merchantStore();

        try {
            $content = file_get_contents($validated['file']->getRealPath());
            $stats = $importer->import(
                $store,
                $content,
                (bool) ($validated['update_existing'] ?? true)
            );

            return response()->json([
                'message' => 'Catálogo importado com sucesso.',
                'stats' => $stats,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao importar catálogo.',
                'details' => $e->getMessage(),
            ], 422);
        }
    }

    private function validateXmlUpload(Request $request): array
    {
        return $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'update_existing' => ['sometimes', 'boolean'],
        ]);
    }
}
