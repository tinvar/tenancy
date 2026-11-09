<?php

declare(strict_types=1);

/**
 * Every action a self-service page gives an app is a name the app's codegen
 * can emit: a TypeScript reserved word is refused there (GeneratedSyntaxTest),
 * so a kit action named `delete` stopped every app that extended its page from
 * building at all.
 */

namespace Tinvar\Tests\Tenancy\SelfService\KitActionNames;

use Tinvar\Attributes\Action;

const RESERVED = [
    'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'debugger', 'default', 'delete', 'do', 'else',
    'enum', 'export', 'extends', 'false', 'finally', 'for', 'function', 'if', 'import', 'in', 'instanceof', 'new',
    'null', 'return', 'super', 'switch', 'this', 'throw', 'true', 'try', 'typeof', 'var', 'void', 'while', 'with',
    'implements', 'interface', 'let', 'package', 'private', 'protected', 'public', 'static', 'yield', 'arguments', 'eval',
];

it('names no self-service page action after a TypeScript reserved word', function (): void {
    $directory = \dirname(__DIR__, 2) . '/src/Tenancy/SelfService/Http';
    $offending = [];
    foreach (glob($directory . '/*.php') ?: [] as $file) {
        $class = 'Tinvar\\Tenancy\\SelfService\\Http\\' . basename($file, '.php');
        if (!class_exists($class)) {
            continue;
        }
        foreach (new \ReflectionClass($class)->getMethods() as $method) {
            if ($method->getAttributes(Action::class) !== [] && \in_array($method->getName(), RESERVED, true)) {
                $offending[] = $class . '::' . $method->getName();
            }
        }
    }

    expect($offending)->toBe([]);
});
