<?php

namespace AdminLby\Anonymous\Tests\integration;

use AdminLby\Anonymous\Privacy;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\User\User;
use FoF\Polls\Poll;
use FoF\Polls\PollOption;
use FoF\Polls\PollVote;
use HuseyinFiliz\Leaderboard\Service\PointService;

// Only legacy redaction plus the prepared guard, without the new backend.
class RollbackCompatibilityTest extends RollbackPrivacyTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-tags', 'fof-seo', 'fof-polls', 'fof-split', 'huseyinfiliz-leaderboard');
        $this->extend(new Extend\Locales(__DIR__.'/../../locale'),
            (new Extend\ApiResource(DiscussionResource::class))->field('user', fn (Schema\Relationship\ToOne $field) =>
                $field->get(fn (Discussion $discussion, Context $context) => Privacy::discussion($discussion) ? null : $context->resource->getValue($discussion, $field, $context))));
    }

    public function test_rollback_retains_poll_creator_and_owner_vote_redaction(): void
    {
        $this->app();
        $poll = Poll::build('Guard test poll', 1, 2, null, true);
        $poll->save();
        $option = PollOption::build('Guard option');
        $poll->options()->save($option);
        PollVote::build($poll->id, 2, $option->id)->save();
        $json = $this->get('/api/polls/'.$poll->id.'?include=user,post.user,votes.user');
        $this->assertTrue($json['data']['attributes']['isAnonymous']);
        $this->assertNull($json['data']['relationships']['user']['data'] ?? null);
        $this->assertStringNotContainsString('secret_original', json_encode($json));
    }

    public function test_rollback_retains_seo_discussion_author_redaction(): void
    {
        $this->app();
        Discussion::query()->whereKey(1)->update(['is_anonymous' => true]);
        $response = $this->send($this->request('GET', '/d/1-anonymous-old-version-fixture'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('secret_original', (string) $response->getBody());
        $this->assertStringContainsString('"@type":"Person"', (string) $response->getBody());
    }

    public function test_rollback_retains_public_leaderboard_filtering(): void
    {
        $points = $this->app()->getContainer()->make(PointService::class);
        $author = User::query()->findOrFail(2);
        $points->award($author, 1, 'daily_login');
        $points->award($author, 1, 'post_created', 1, 'post');
        $json = $this->get('/api/leaderboard-entries');
        $entry = array_values(array_filter($json['data'], fn ($row) => $row['id'] === '2'))[0];
        $this->assertSame(1, $entry['attributes']['points']);
        $this->assertSame(1, $this->get('/api/users/2')['data']['attributes']['leaderboardPoints']);
    }
}
