<?php

namespace AdminLby\Anonymous\Tests\integration;

use Flarum\User\User;
use FoF\Polls\Poll;
use HuseyinFiliz\Leaderboard\Service\PointService;

// Run the same real conversion/permission/history/notification regressions
// again with the user's exact additional plugin versions enabled together.
class ConversionCompatibilityTest extends IdentityConversionTest
{
    protected function repeatedQueryThreshold(): int
    {
        // Core SSR plus SEO's own preload performs fixed actor/user lookups.
        return max(8, parent::repeatedQueryThreshold());
    }

    protected function additionalExtensions(): array
    {
        return ['flarum-tags', 'fof-seo', 'fof-polls', 'fof-split', 'huseyinfiliz-leaderboard'];
    }

    public function test_optional_seo_discussion_and_reply_schema_never_link_anonymous_authors(): void
    {
        $this->convert(1, true);
        $first = $this->send($this->request('GET', '/d/1-conversion-fixture'));
        $this->assertSame(200, $first->getStatusCode());
        $this->assertStringNotContainsString('converted_secret_author', (string) $first->getBody());
        $this->assertStringContainsString('application/ld+json', (string) $first->getBody());
        $this->convert(4, true);
        $reply = $this->send($this->request('GET', '/d/2-other-fixture'));
        $this->assertSame(200, $reply->getStatusCode());
        $html = (string) $reply->getBody();
        $this->assertStringNotContainsString('converted_secret_reply', $html);
        $this->assertStringContainsString('Anonymous user', $html);
        $this->convert(4, false);
        $named = $this->send($this->request('GET', '/d/2-other-fixture'));
        $this->assertStringContainsString('converted_secret_reply', (string) $named->getBody());
    }

    public function test_optional_poll_creator_and_owner_vote_follow_post_anonymity(): void
    {
        $this->app();
        $poll = Poll::build('Conversion test poll', 1, 2, null, true);
        $poll->save();
        $option = \FoF\Polls\PollOption::build('One option');
        $poll->options()->save($option);
        \FoF\Polls\PollVote::build($poll->id, 2, $option->id)->save();
        \FoF\Polls\PollVote::build($poll->id, 3, $option->id)->save();
        $this->convert(1, true);
        $json = $this->json($this->api('GET', '/api/polls/'.$poll->id.'?include=user,post.user,votes.user'));
        $this->privateDocument($json, 2, 'converted_secret_author');
        $this->assertTrue($json['data']['attributes']['isAnonymous']);
        $this->assertNull($json['data']['relationships']['user']['data'] ?? null);
        $votes = array_values(array_filter($json['included'], fn ($row) => $row['type'] === 'poll_votes'));
        $this->assertCount(2, $votes);
        $this->assertTrue($votes[0]['attributes']['isAnonymous']);
        $this->assertNull($votes[0]['relationships']['user']['data'] ?? null);
        $this->assertSame('3', $votes[1]['relationships']['user']['data']['id']);
        $this->convert(1, false);
        $named = $this->json($this->api('GET', '/api/polls/'.$poll->id.'?include=user'));
        $this->assertFalse($named['data']['attributes']['isAnonymous']);
        $this->assertSame('2', $named['data']['relationships']['user']['data']['id']);
    }

    public function test_optional_split_inherits_the_converted_anonymous_first_post(): void
    {
        $this->convert(4, true);
        $response = $this->send($this->request('POST', '/api/split', ['authenticatedAs' => 1, 'json' => [
            'start_post_id' => 4, 'end_post_number' => 2, 'title' => 'Split anonymous conversion',
        ]]));
        $this->json($response);
        $post = $this->database()->table('posts')->find(4);
        $this->assertNotSame(2, (int) $post->discussion_id);
        $discussion = $this->json($this->api('GET', '/api/discussions/'.$post->discussion_id.'?include=user,firstPost.user,lastPostedUser'));
        $this->privateDocument($discussion, 6, 'converted_secret_reply');
        $this->assertTrue($discussion['data']['attributes']['isAnonymous']);
        $this->assertNull($discussion['data']['relationships']['user']['data'] ?? null);
        $this->convert(4, false);
        $this->assertSame('6', $this->json($this->api('GET', '/api/discussions/'.$post->discussion_id))['data']['relationships']['user']['data']['id']);
    }

    public function test_optional_leaderboard_and_profile_points_exclude_anonymous_sources(): void
    {
        $container = $this->app()->getContainer();
        $points = $container->make(PointService::class);
        $author = User::query()->findOrFail(2);
        $points->award($author, 1, 'discussion_started', 1, 'discussion');
        $points->award($author, 1, 'post_created', 1, 'post');
        $this->database()->table('users')->where('id', 2)->update(['comment_count' => 900000, 'discussion_count' => 900000]);
        $privateTotal = $this->database()->table('leaderboard_user_totals')->where('user_id', 2)->value('points_total');
        $this->convert(1, true);
        $json = $this->json($this->api('GET', '/api/leaderboard-entries', 1));
        $entry = array_values(array_filter($json['data'], fn ($row) => $row['id'] === '2'))[0];
        $this->assertSame(1, $entry['attributes']['points'], 'Only the independent daily-login award should remain public');
        $user = array_values(array_filter($json['included'], fn ($row) => $row['id'] === '2'))[0];
        $this->assertSame(0, $user['attributes']['commentCount']);
        $this->assertSame(0, $user['attributes']['discussionCount']);
        $this->assertSame(1, $this->json($this->api('GET', '/api/users/2', 1))['data']['attributes']['leaderboardPoints']);
        $this->assertSame($privateTotal, $this->database()->table('leaderboard_user_totals')->where('user_id', 2)->value('points_total'));
        $this->convert(1, false);
        $this->assertSame(3, $this->json($this->api('GET', '/api/users/2', 1))['data']['attributes']['leaderboardPoints']);
    }
}
