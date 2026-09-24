<?php
declare(strict_types=1);

namespace app\optional;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;
use League\CommonMark\Extension\Mention\Mention;
use League\CommonMark\Extension\Mention\MentionExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

if (!defined('APP_ROOT')) exit;

/**
 * 帖子正文的 Markdown 渲染，基于开源库 league/commonmark：
 * 核心语法 + GFM 表格 / 删除线 / 裸链接自动识别，再加官方 Mention 扩展处理 @用户 与 @用户 #楼层。
 * 安全默认值：正文里的原始 HTML 一律转义输出，javascript: 之类的不安全链接不生成 a 标签。
 */
final class Markdown
{
    /** 一次请求内所有正文共用一个转换器（按 topic_id 建多份会把内存吃光） */
    private static ?MarkdownConverter $converter = null;
    /** 当前渲染的主题 id：提及链接要它才能生成楼层链接，PHP 单线程逐条渲染，用静态上下文传递 */
    private static int $topicId = 0;

    public static function html(string $text, int $topic_id = 0): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') return '';
        self::$topicId = $topic_id;
        return (string)self::converter()->convert($text);
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter instanceof MarkdownConverter) return self::$converter;
        $environment = new Environment([
            // 帖子历来按“换行即换行”书写，soft break 渲染成 <br> 才能保持原有观感；
            // 块之间不再插换行，输出与旧渲染器一致（也省掉模板里多余的空白文本节点）
            'renderer' => ['soft_break' => '<br>', 'block_separator' => '', 'inner_separator' => ''],
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            // 限制嵌套深度，避免深层嵌套把解析栈打爆
            'max_nesting_level' => 100,
            'default_attributes' => [
                Image::class => ['loading' => 'lazy', 'referrerpolicy' => 'no-referrer'],
            ],
            'mentions' => [
                'user' => [
                    'prefix' => '@',
                    // 用户名：字母数字下划线连字符，允许用点分段；后面可以再跟 “#楼层”
                    'pattern' => '[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*(?:\s+#[0-9]{1,9})?',
                    'generator' => static function (Mention $mention): ?Mention {
                        $identifier = (string)$mention->getIdentifier();
                        if (preg_match('/^(.+?)\s+#([0-9]+)$/u', $identifier, $match)) {
                            // 没有主题上下文（新发帖预览等）时不生成链接，原样保留 @用户名 #楼层
                            if (self::$topicId <= 0) return null;
                            $mention->setUrl(route_url('topic', ['id' => self::$topicId, 'floor' => (int)$match[2]]));
                            $mention->data->set('attributes', [
                                'class' => 'post-mention post-floor-mention',
                                'target' => '_blank',
                                'rel' => 'noopener',
                            ]);
                            return $mention;
                        }
                        $mention->setUrl(route_url('user', ['username' => $identifier]));
                        $mention->data->set('attributes', ['class' => 'post-mention']);
                        return $mention;
                    },
                ],
            ],
        ]);
        foreach ([
            new CommonMarkCoreExtension(),
            new TableExtension(),
            new StrikethroughExtension(),
            new AutolinkExtension(),
            new MentionExtension(),
            new DefaultAttributesExtension(),
        ] as $extension) {
            $environment->addExtension($extension);
        }
        return self::$converter = new MarkdownConverter($environment);
    }
}
