<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Expense\Interface\Http\Controller\ExpenseController;

Route::get('/shipments/{shipmentId}/expenses', [ExpenseController::class, 'index'])->whereUuid('shipmentId')->can('expenses.read');
Route::post('/shipments/{shipmentId}/expenses', [ExpenseController::class, 'store'])->whereUuid('shipmentId')->can('expenses.create');
Route::patch('/expenses/{expenseId}', [ExpenseController::class, 'update'])->whereUuid('expenseId')->can('expenses.update');
Route::delete('/expenses/{expenseId}', [ExpenseController::class, 'destroy'])->whereUuid('expenseId')->can('expenses.delete');
Route::post('/expenses/{expenseId}/validate', [ExpenseController::class, 'validateExpense'])->whereUuid('expenseId')->can('expenses.validate');
