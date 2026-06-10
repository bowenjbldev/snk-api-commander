<?php

namespace Packages\Core\Scramble\DocumentTransformers;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

class CoreBearerSecuritySchemeDocumentTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->components->addSecurityScheme(
            'bearerAuth',
            SecurityScheme::http('bearer')->as('bearerAuth')->setDescription('Bearer token authentication (Sanctum).')
        );
    }
}
