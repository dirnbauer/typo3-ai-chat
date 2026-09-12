<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use Webconsulting\Typo3AiChat\Controller\ChatApiController;
use Webconsulting\Typo3AiChat\Domain\Repository\MessageRepository;
use Webconsulting\Typo3AiChat\Testing\ScriptedProvider;
use Webconsulting\Typo3AiChat\Tests\Functional\AbstractChatFunctionalTestCase;
use Webconsulting\Typo3AiChat\Tests\Functional\DecodesApiResponses;

/**
 * The spend cap.
 *
 * Every turn is a paid provider call, so the limit is per USER rather than per
 * conversation — opening a second conversation must not double the bill. The
 * per-conversation limit is a different mechanism entirely (the processing
 * claim), and this test is careful to exercise the user one by keeping the same
 * conversation idle between turns.
 */
final class TurnRateLimitTest extends AbstractChatFunctionalTestCase
{
    use DecodesApiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_webconsultingaichat_conversation.csv');
    }

    #[Test]
    public function theEleventhTurnInAMinuteIsRefused(): void
    {
        ScriptedProvider::script(array_fill(0, 12, ['content' => 'ok']));

        for ($i = 1; $i <= 10; ++$i) {
            self::assertSame(
                200,
                $this->turn('message ' . $i)->getStatusCode(),
                sprintf('Turn %d is within the configured limit of 10 per minute.', $i),
            );
        }

        $refused = $this->turn('one too many');

        self::assertSame(429, $refused->getStatusCode());
        self::assertStringContainsString('too many turns', $this->stringAt($this->decodeJson($refused, 429), 'error'));
    }

    #[Test]
    public function arefusedTurnCostsNothingAndPersistsNothing(): void
    {
        ScriptedProvider::script(array_fill(0, 10, ['content' => 'ok']));

        for ($i = 1; $i <= 10; ++$i) {
            $this->turn('message ' . $i);
        }

        $before = $this->get(MessageRepository::class)->countByConversation(1);
        $this->turn('one too many');
        $after = $this->get(MessageRepository::class)->countByConversation(1);

        self::assertSame($before, $after, 'A refused turn must not even record the question.');
    }

    #[Test]
    public function theStatusEndpointReportsWhatIsLeft(): void
    {
        ScriptedProvider::script([['content' => 'ok']]);
        $this->turn('one');

        $controller = $this->get(ChatApiController::class);
        self::assertInstanceOf(ChatApiController::class, $controller);

        $body = $this->decodeJson($controller->status());

        self::assertSame(10, $this->intAt($body, 'limits', 'turnsPerMinute'));
        self::assertSame(
            9,
            $this->intAt($body, 'limits', 'turnsRemaining'),
            'The client can warn before the user hits the wall, rather than after.',
        );
    }

    private function turn(string $content): ResponseInterface
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/webconsulting/ai-chat/conversations/turn', 'POST'))
            ->withParsedBody(['conversation' => 1, 'content' => $content]);

        $controller = $this->get(ChatApiController::class);
        self::assertInstanceOf(ChatApiController::class, $controller);

        return $controller->turn($request);
    }
}
