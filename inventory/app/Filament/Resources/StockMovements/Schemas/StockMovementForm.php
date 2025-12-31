<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StockMovementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('product_id')
                    ->required()
                    ->numeric(),
                Select::make('type')
                    ->options(['in' => 'In', 'out' => 'Out', 'adjust' => 'Adjust'])
                    ->required(),
                TextInput::make('quantity')
                    ->required()
                    ->numeric(),
                TextInput::make('reference'),
                TextInput::make('user_id')
                    ->required()
                    ->numeric(),
                TextInput::make('notes'),
            ]);
    }
}
