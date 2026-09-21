<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\LoginActivityController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TicketMessageController;
use App\Http\Controllers\Api\UserController;
use App\Models\Department;
use App\Models\LoginActivity;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:6,1')
    ->name('verification.verify');
Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
    ->middleware('throttle:verification')
    ->name('verification.send');

Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])
    ->middleware('throttle:password-reset')
    ->name('password.email');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:password-reset')
    ->name('password.store');

// Authenticated (Sanctum Bearer token) and account still active
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Sign-in history (IP addresses and devices): requires the `activity.view` permission.
    Route::get('/login-activities', [LoginActivityController::class, 'index'])->can('viewAny', LoginActivity::class);

    // Tickets. Visible to view_all holders, the customer who opened it and its assignee (see TicketPolicy).
    Route::prefix('tickets')->group(function () {
        Route::get('/', [TicketController::class, 'index'])->can('viewAny', Ticket::class);
        Route::post('/', [TicketController::class, 'store'])->can('create', Ticket::class)->middleware('throttle:ticket-create');
        // Must be declared before '/{ticket}' so it is not treated as an id.
        Route::get('/options', [TicketController::class, 'options'])->can('create', Ticket::class);
        Route::get('/{ticket}', [TicketController::class, 'show'])->can('view', 'ticket');
        // Which status changes are allowed is decided by App\Support\TicketWorkflow (checked in the controller).
        Route::post('/{ticket}/status', [TicketController::class, 'updateStatus'])->can('changeStatus', 'ticket');
        Route::put('/{ticket}/priority', [TicketController::class, 'updatePriority'])->can('changePriority', 'ticket');
        Route::put('/{ticket}/assignee', [TicketController::class, 'updateAssignee'])->can('assign', 'ticket');
        Route::get('/{ticket}/assignable-agents', [TicketController::class, 'assignableAgents'])->can('assign', 'ticket');

        Route::get('/{ticket}/messages', [TicketMessageController::class, 'index'])->can('view', 'ticket');
        Route::post('/{ticket}/replies', [TicketMessageController::class, 'reply'])->can('reply', 'ticket')->middleware('throttle:ticket-message');
        Route::post('/{ticket}/internal-notes', [TicketMessageController::class, 'note'])->can('addNote', 'ticket')->middleware('throttle:ticket-message');
    });

    // Departments. Managers see all; other viewers (agents) see only their own (see DepartmentPolicy).
    Route::prefix('departments')->group(function () {
        Route::get('/', [DepartmentController::class, 'index'])->can('viewAny', Department::class);
        Route::post('/', [DepartmentController::class, 'store'])->can('create', Department::class);
        // Must be declared before '/{department}' so it is not treated as an id.
        Route::get('/assignable-staff', [DepartmentController::class, 'assignableStaff'])->can('manageMembers', Department::class);
        Route::get('/{department}', [DepartmentController::class, 'show'])->can('view', 'department');
        Route::patch('/{department}', [DepartmentController::class, 'update'])->can('update', 'department');
        Route::delete('/{department}', [DepartmentController::class, 'destroy'])->can('delete', 'department');
        Route::put('/{department}/members', [DepartmentController::class, 'updateMembers'])->can('manageMembers', 'department');
    });

    // Administrative user management. `can:` runs UserPolicy (permission + Super Admin protection).
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])->can('viewAny', User::class);
        Route::post('/', [UserController::class, 'store'])->can('create', User::class);
        // Must be declared before '/{user}' so it is not treated as a user id.
        Route::get('/assignable-roles', [UserController::class, 'assignableRoles'])->can('viewAny', User::class);
        Route::get('/{user}', [UserController::class, 'show'])->can('view', 'user');
        Route::patch('/{user}', [UserController::class, 'update'])->can('update', 'user');
        Route::post('/{user}/activate', [UserController::class, 'activate'])->can('activate', 'user');
        Route::post('/{user}/deactivate', [UserController::class, 'deactivate'])->can('deactivate', 'user');
        Route::put('/{user}/role', [UserController::class, 'updateRole'])->can('assignRole', 'user');
    });
});
