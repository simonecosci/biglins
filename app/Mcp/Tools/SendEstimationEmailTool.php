<?php

namespace App\Mcp\Tools;

use App\Http\Requests\SendEstimationRequest;
use App\Mail\EstimationMail;
use App\Models\Estimation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('send_estimation_email')]
#[Description('Email an existing estimation to a recipient.')]
#[IsDestructive]
#[IsOpenWorld]
class SendEstimationEmailTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $data = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
                'estimation_id' => ['required', 'uuid'],
            ]);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $estimation = Estimation::query()->where('company_id', (string) $data['company_id'])->find((string) $data['estimation_id']);

        if ($estimation === null) {
            return Response::error('No estimation with that id was found for the given company.');
        }

        try {
            $mailData = $request->validate((new SendEstimationRequest)->rules());
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        Mail::to($mailData['to'])->send(new EstimationMail($estimation, $mailData['subject'], $mailData['message']));

        $estimation->sent_at = Carbon::now();
        $estimation->sent_to = $mailData['to'];
        $estimation->save();

        return Response::structured(['estimation' => $estimation->fresh()->toArray()]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company the estimation belongs to.')->required(),
            'estimation_id' => $schema->string()->description('UUID of the estimation to send.')->required(),
            'to' => $schema->string()->format('email')->required(),
            'subject' => $schema->string()->required(),
            'message' => $schema->string()->description('Email body.')->required(),
        ];
    }
}
