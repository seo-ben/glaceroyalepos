<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class UserController extends Controller
{
    use AuthorizesRequests;
    public function index(Request $request)
    {
        $users = User::with('role')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->search, fn($q) => $q->where(function($q) use ($request) {
                $q->where('first_name', 'like', "%{$request->search}%")
                  ->orWhere('last_name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->role_id, fn($q) => $q->where('role_id', $request->role_id))
            ->when(isset($request->active), fn($q) => $q->where('active', $request->boolean('active')))
            ->orderBy('first_name')
            ->paginate(20);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        // Pré-traitement pour éviter les problèmes avec les chaînes vides
        if ($request->has('pin_code') && $request->pin_code === '') {
            $request->merge(['pin_code' => null]);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|unique:users,email,NULL,id,deleted_at,NULL',
            'password'   => 'required|min:8',
            'role_id'    => 'required|exists:roles,id',
            'pin'        => 'nullable|numeric|digits_between:4,6',
            'pin_code'   => 'nullable|numeric|digits_between:4,6',
            'active'     => 'boolean',
            'is_active'  => 'boolean',
        ]);

        if ($request->has('pin_code')) $validated['pin'] = $request->pin_code;
        if ($request->has('is_active')) $validated['active'] = $request->is_active;

        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['password']      = Hash::make($validated['password']);
        
        if (isset($validated['pin']) && $validated['pin']) {
            $validated['pin'] = Hash::make($validated['pin']);
        }

        // Nettoyage
        unset($validated['pin_code'], $validated['is_active']);

        $user = User::create($validated);

        return response()->json($user->load('role'), 201);
    }

    public function show(Request $request, User $user)
    {
        $this->authorizeRestaurant($request, $user);
        return response()->json($user->load('role'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeRestaurant($request, $user);
        $this->authorize('update', $user);

        // Pré-traitement pour éviter les problèmes avec les chaînes vides
        if ($request->has('pin_code') && $request->pin_code === '') {
            $request->merge(['pin_code' => null]);
        }
        if ($request->has('password') && $request->password === '') {
            $request->merge(['password' => null]);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name'  => 'sometimes|string|max:100',
            'email'      => "sometimes|email|unique:users,email,{$user->id},id,deleted_at,NULL",
            'role_id'    => 'sometimes|exists:roles,id',
            'pin'        => 'nullable|numeric|digits_between:4,6',
            'pin_code'   => 'nullable|numeric|digits_between:4,6',
            'active'     => 'sometimes|boolean',
            'is_active'  => 'sometimes|boolean',
            'password'   => 'nullable|min:8',
        ]);

        // Mapping des alias
        if ($request->has('pin_code')) $validated['pin'] = $request->pin_code;
        if ($request->has('is_active')) $validated['active'] = $request->is_active;

        // Traitement du mot de passe
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        // Traitement du PIN
        if (isset($validated['pin'])) {
            // Si le PIN est null (effacé), on l'enregistre tel quel, sinon on le hash
            $validated['pin'] = $validated['pin'] ? Hash::make($validated['pin']) : null;
        }

        // Nettoyage des champs qui ne sont pas dans le modèle
        unset($validated['pin_code'], $validated['is_active']);

        $shouldRevokeTokens = 
            isset($validated['password']) ||
            array_key_exists('pin', $validated) ||
            array_key_exists('email', $validated) ||
            array_key_exists('active', $validated);

        $user->update($validated);

        if ($shouldRevokeTokens) {
            $user->tokens()->delete();
        }

        return response()->json($user->fresh('role'));
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorize('delete', $user);
        $this->authorizeRestaurant($request, $user);

        $user->delete();
        return response()->json(['message' => 'Utilisateur supprimé.']);
    }

    public function toggleActive(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $user->update(['active' => !$user->active]);
        return response()->json($user);
    }

    private function authorizeRestaurant(Request $request, User $user): void
    {
        abort_if(
            $user->restaurant_id !== $request->user()->restaurant_id,
            403, 'Accès non autorisé.'
        );
    }
}
