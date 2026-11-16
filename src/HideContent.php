<?php

namespace forumaker\MagicBB;

use Flarum\Http\RequestUtil;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use s9e\TextFormatter\Renderer;
use Symfony\Contracts\Translation\TranslatorInterface;

class HideContent
{
    private array $likedCache = [];
    private array $repliedCache = [];

    public function __construct(
        protected TranslatorInterface $translator,
        protected LoggerInterface     $logger,
    ) {}

    public function __invoke(
        Renderer $renderer,
        mixed $context,
        string $xml,
        ?ServerRequestInterface $request = null
    ): string {
        if (!$context instanceof CommentPost) {
            return $xml;
        }

        if (!$this->containsHideTags($xml)) {
            return $xml;
        }

        $post  = $context;
        $actor = $request ? RequestUtil::getActor($request) : null;

        // No actor (email notifications, queue jobs, CLI) or a guest: treat as
        // unauthenticated rather than leaking the gated content.
        if ($actor === null || $actor->isGuest()) {
            $hint = $this->translator->trans('forumaker-magicbb.forum.hide.login_to_see_simple');

            $xml = $this->apply($xml, 'LOGIN', false, '登录可见', $hint);
            $xml = $this->apply($xml, 'LIKE', false, '点赞可见', $hint);
            $xml = $this->apply($xml, 'REPLY', false, '回复可见', $hint);

            return $xml;
        }

        $isAuthor = (int) $actor->id === (int) $post->user_id;
        $ownerTag = $isAuthor ? ' [作者可见]' : '';

        // [LOGIN] — visible to every logged in user.
        $xml = $this->apply($xml, 'LOGIN', true, '登录可见', '');

        if ($this->hasTag($xml, 'LIKE')) {
            if ($isAuthor
                || $actor->hasPermission('post.bypasslikeRequirement')
                || $this->actorLikedPost($actor, $post)
            ) {
                $xml = $this->apply($xml, 'LIKE', true, '点赞可见' . $ownerTag, '');
            } else {
                $xml = $this->apply(
                    $xml,
                    'LIKE',
                    false,
                    '点赞可见',
                    $this->translator->trans('forumaker-magicbb.forum.hide.like_to_see_simple')
                );
            }
        }

        if ($this->hasTag($xml, 'REPLY')) {
            if ($isAuthor
                || $actor->hasPermission('post.bypassreplyRequirement')
                || $this->actorHasReplied($actor, $post)
            ) {
                $xml = $this->apply($xml, 'REPLY', true, '回复可见' . $ownerTag, '');
            } else {
                $xml = $this->apply(
                    $xml,
                    'REPLY',
                    false,
                    '回复可见',
                    $this->translator->trans('forumaker-magicbb.forum.hide.reply_to_see_simple')
                );
            }
        }

        return $xml;
    }

    /**
     * Rewrite a <TAG> node in place.
     *
     * The tag is always kept (so the template can draw the dashed container),
     * `label` carries the header text, and locked bodies are replaced by the
     * hint message. The BBCode's <s>/<e> markers are dropped either way, which
     * is what the previous reveal/hide helpers did implicitly.
     */
    private function apply(string $xml, string $tag, bool $unlocked, string $label, string $hint): string
    {
        $pattern = '/<' . $tag . '>(?:<s>.*?<\/s>)?(.*?)(?:<e>.*?<\/e>)?<\/' . $tag . '>/si';

        if (!preg_match($pattern, $xml)) {
            return $xml;
        }

        $result = preg_replace_callback(
            $pattern,
            function (array $m) use ($tag, $unlocked, $label, $hint): string {
                $body = $unlocked ? $m[1] : htmlspecialchars($hint, ENT_XML1);

                return '<' . $tag . ' label="' . htmlspecialchars($label, ENT_XML1) . '">'
                    . $body
                    . '</' . $tag . '>';
            },
            $xml
        );

        return $result ?? $xml;
    }

    private function containsHideTags(string $xml): bool
    {
        return str_contains($xml, '<LOGIN')
            || str_contains($xml, '<LIKE')
            || str_contains($xml, '<REPLY');
    }

    private function hasTag(string $xml, string $tag): bool
    {
        return str_contains($xml, '<' . $tag);
    }

    private function actorLikedPost(User $actor, CommentPost $post): bool
    {
        $aid = (int) $actor->id;
        $did = (int) $post->discussion_id;

        if (!array_key_exists($did, $this->likedCache[$aid] ?? [])) {
            $ids = [];

            // flarum/likes is optional and registers the likes() relation on
            // CommentPost as a macro (not a real method), so it can't be probed with
            // method_exists()/class_exists(). Attempt the query through the model
            // layer and fall back gracefully if the relation isn't registered.
            try {
                $ids = Post::where('discussion_id', $did)
                    ->whereHas('likes', fn ($q) => $q->where('id', $aid))
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'forumaker-magicbb: could not load likes for discussion — is flarum/likes installed?',
                    ['exception' => get_class($e) . ': ' . $e->getMessage()]
                );
            }

            $this->likedCache[$aid][$did] = $ids;
        }

        return in_array((int) $post->id, $this->likedCache[$aid][$did], true);
    }

    private function actorHasReplied(User $actor, CommentPost $post): bool
    {
        $aid = (int) $actor->id;
        $did = (int) $post->discussion_id;

        if (!array_key_exists($did, $this->repliedCache[$aid] ?? [])) {
            $replied = false;
            try {
                $replied = Post::where('user_id', $aid)
                    ->where('discussion_id', $did)
                    ->where('type', 'comment')
                    ->exists();
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'forumaker-magicbb: could not query posts for reply check',
                    ['exception' => get_class($e) . ': ' . $e->getMessage()]
                );
            }
            $this->repliedCache[$aid][$did] = $replied;
        }

        return $this->repliedCache[$aid][$did];
    }
}
