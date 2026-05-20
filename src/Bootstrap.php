<?php

declare(strict_types=1);

namespace App;

use App\Config\Env;
use App\Repository\AuctionRepositoryInterface;
use App\Repository\FileAuctionRepository;
use App\Repository\MongoAuctionRepository;
use App\Service\AuctionService;
use App\Support\SystemClock;

final class Bootstrap
{
    public static function service(): AuctionService
    {
        $root = dirname(__DIR__);
        Env::load($root . '/.env');

        $repository = self::repository($root);

        return new AuctionService($repository, new SystemClock());
    }

    private static function repository(string $root): AuctionRepositoryInterface
    {
        $mongoUri = Env::string('MONGO_URI', '');
        $database = Env::string('MONGO_DATABASE', 'aiodin_bidding');
        $productsCollection = Env::string('MONGO_PRODUCTS_COLLECTION', 'products');
        $biddingsCollection = Env::string('MONGO_BIDDINGS_COLLECTION', 'biddings');
        $username = Env::string('MONGO_USERNAME', '');
        $password = Env::string('MONGO_PASSWORD', '');

        if ($mongoUri !== '' && extension_loaded('mongodb')) {
            return new MongoAuctionRepository(
                $mongoUri,
                $database,
                $productsCollection,
                $biddingsCollection,
                $username,
                $password
            );
        }

        return new FileAuctionRepository(
            $root . '/' . Env::string('PRODUCTS_STORAGE_PATH', 'storage/products.json'),
            $root . '/' . Env::string('BIDDINGS_STORAGE_PATH', 'storage/biddings.json')
        );
    }
}
