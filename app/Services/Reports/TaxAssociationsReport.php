<?php

namespace App\Services\Reports;

use App\Models\MenuItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class TaxAssociationsReport
{
    public function paginate(): LengthAwarePaginator
    {
        return MenuItem::with('category.superCategory.taxClass')
            ->whereHas('category', fn (Builder $category) => $category->withoutTrashed()->whereHas('superCategory.taxClass'))
            ->orderBy('name')->orderBy('id')
            ->paginate(20);
    }
}
