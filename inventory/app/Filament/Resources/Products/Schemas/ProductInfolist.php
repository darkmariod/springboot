<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('sku')
                    ->label('SKU'),
                TextEntry::make('name'),
                TextEntry::make('description'),
                TextEntry::make('price')
                    ->money(),
                TextEntry::make('cost')
                    ->money(),
                TextEntry::make('stock')
                    ->numeric(),
                TextEntry::make('min_stock')
                    ->numeric(),
                TextEntry::make('category_id')
                    ->numeric(),
                TextEntry::make('supplier_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
