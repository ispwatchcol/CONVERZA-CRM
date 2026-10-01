<?php

namespace App\Services\Flows;

use App\Services\Flows\Nodes\ConditionNode;
use App\Services\Flows\Nodes\CustomerLookupNode;
use App\Services\Flows\Nodes\EndNode;
use App\Services\Flows\Nodes\HandoffNode;
use App\Services\Flows\Nodes\MenuNode;
use App\Services\Flows\Nodes\MessageNode;
use App\Services\Flows\Nodes\NodeType;
use App\Services\Flows\Nodes\QuestionNode;
use App\Services\Flows\Nodes\StartNode;
use App\Services\Flows\Nodes\TagNode;
use App\Services\Flows\Nodes\WaitNode;

/**
 * Los bloques de la librería v1 (CON-49). Un bloque que no está aquí no se
 * puede publicar ni ejecutar.
 */
final class NodeRegistry
{
    /** @var array<string, NodeType> */
    private array $types = [];

    public function __construct()
    {
        foreach ([
            new StartNode(),
            new MessageNode(),
            new QuestionNode(),
            new MenuNode(),
            new ConditionNode(),
            new CustomerLookupNode(),
            new TagNode(),
            new WaitNode(),
            new HandoffNode(),
            new EndNode(),
        ] as $type) {
            $this->types[$type->type()] = $type;
        }
    }

    public function get(string $type): ?NodeType
    {
        return $this->types[$type] ?? null;
    }

    /** @return array<string, NodeType> */
    public function all(): array
    {
        return $this->types;
    }
}
