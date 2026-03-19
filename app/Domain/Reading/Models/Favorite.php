<?php

declare(strict_types=1);

namespace App\Domain\Reading\Models;

use App\Domain\AudioBook\Models\AudioBook;
use App\Domain\Book\Models\Book;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Favorite extends Model
{
    public $timestamps = false;
    protected $table = 'favorites';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'book_id',
        'audiobook_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
        static::creating(function (self $fav): void {
            if (app()->has('tenant') && empty($fav->tenant_id)) {
                $fav->tenant_id = app('tenant')->id;
            }
            $fav->created_at = now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function audioBook(): BelongsTo
    {
        return $this->belongsTo(AudioBook::class, 'audiobook_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
