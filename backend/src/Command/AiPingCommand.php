<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Ai\Llm\LlmClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks the AI setup with one tiny request (a few tokens): key, network, model. Never prints the key.
 */
#[AsCommand(name: 'app:ai:ping', description: 'Check that the Claude API key works (one short request)')]
final class AiPingCommand extends Command
{
    public function __construct(private readonly LlmClient $llm)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->llm->isConfigured()) {
            $io->warning('ANTHROPIC_API_KEY is not set: AI features are off.');

            return Command::FAILURE;
        }

        $reply = $this->llm->send('Отвечай одним словом.', [['role' => 'user', 'content' => 'Скажи «работает».']], effort: 'low', maxTokens: 2000);
        $io->success(\sprintf('Model %s answered "%s" (%d in / %d out tokens).', $reply->model, $reply->text, $reply->inputTokens, $reply->outputTokens));

        return Command::SUCCESS;
    }
}
