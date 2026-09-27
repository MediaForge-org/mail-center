<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Organization\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FolderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $folders = DB::table('folders')->where('user_id', $userId)
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'name', 'system_role', 'position'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'system_role' => $row->system_role, 'position' => (int) $row->position])
            ->values();

        return response()->json(['data' => $folders]);
    }

    public function store(Request $request, OrganizationService $organization): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string']]);

        return response()->json(['data' => $organization->createFolder((int) $request->user()->getAuthIdentifier(), $data['name'])], 201);
    }

    public function update(Request $request, int $id, OrganizationService $organization): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string']]);

        return response()->json(['data' => $organization->renameFolder((int) $request->user()->getAuthIdentifier(), $id, $data['name'])]);
    }

    public function order(Request $request, OrganizationService $organization): JsonResponse
    {
        $data = $request->validate([
            'folder_ids' => ['required', 'array', 'min:1'],
            'folder_ids.*' => ['integer', 'min:1'],
        ]);
        $organization->reorderFolders((int) $request->user()->getAuthIdentifier(), $data['folder_ids']);

        return response()->json(['data' => true]);
    }

    public function destroy(Request $request, int $id, OrganizationService $organization): JsonResponse
    {
        return response()->json(['data' => $organization->deleteFolder((int) $request->user()->getAuthIdentifier(), $id)]);
    }
}
