<?php

use App\Models\User;
use Laravel\Passport\Passport;

test('the web MCP endpoint rejects requests without a token', function () {
    $response = $this->postJson('/mcp/biglins', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], ['Accept' => 'application/json, text/event-stream']);

    $response->assertUnauthorized();
});

test('the web MCP endpoint accepts a valid Sanctum token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('agent')->plainTextToken;

    $response = $this->postJson('/mcp/biglins', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], [
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertOk();
});

test('the web MCP endpoint accepts a valid Passport OAuth token', function () {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $response = $this->postJson('/mcp/biglins', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], ['Accept' => 'application/json, text/event-stream']);

    $response->assertOk();
});
