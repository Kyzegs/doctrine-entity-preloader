<?php declare(strict_types = 1);

namespace ShipMonk\DoctrineEntityPreloader;

enum PreloadHydrationMode: string
{

    case FullAssociation = 'full-association';
    case PartialCollection = 'partial-collection';

}
