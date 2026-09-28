<?php
declare(strict_types=1);

namespace app\optional;

use app\optional\Model\Topic;
use PDO;

/**
 * 关键词聚合页（话题页）：站内话题词库 + 全文索引聚合出的主题列表。
 *
 * 定位（programmatic SEO）：把语料里验证过热度的行业关键词（见 pipeline 关键词库报告
 * output/keyword-bank-*.md）做成 /tag/{关键词} 落地页，聚合站内相关主题，给长尾搜索
 * 一个比单篇帖子更完整的入口。页面文案按「定义式答案块」撰写，兼顾生成式引擎引用。
 *
 * 与 SearchIndex 的关系：只读它的 MATCH 表达式构建（match_plan），不维护索引本体；
 * FTS 不可用时回落标题 LIKE，页面仍然可用。
 */
final class TopicTags
{
    /** 表结构版本：加列/改列时 +1，门闩保证只执行一次 */
    private const SCHEMA_VERSION = 1;
    /** 种子词库版本：补充/修订话题词时 +1（INSERT OR IGNORE，改 summary 需要手工 UPDATE） */
    private const SEED_VERSION = 1;
    /** 话题页主题列表与 FTS 候选上限 */
    private const LIST_LIMIT_MAX = 100;
    private const FTS_CANDIDATES = 200;

    /**
     * 种子话题词库（keyword, chain, summary）。
     * chain 沿用管线产业链环节命名；summary 是页面首段与 meta description 的定义式答案块。
     */
    private const SEEDS = [
        // ---- 回收收集 ----
        ['旧衣回收', '回收收集', '旧衣回收是把居民闲置的旧衣服集中收集、分拣后再流通或再利用的循环体系，这里汇总回收行业动态、政策法规与从业模式分析。'],
        ['旧衣回收箱', '回收收集', '旧衣回收箱是设置在社区、学校等场所的衣物定点回收设施，回收箱的投放备案、清运频次与规范化管理是回收行业的监管重点。'],
        ['上门回收', '回收收集', '上门回收指平台或回收商上门收取旧衣服的服务模式，一般按公斤计价、预约下单，是近年国内旧衣回收的主要获客方式。'],
        ['旧衣服捐赠', '回收收集', '旧衣服捐赠是把闲置衣物捐给公益机构或困难群体的处理方式，可捐赠品类、清洗消毒与最终流向是公众与媒体关注的焦点。'],
        ['旧衣服回收价格', '回收收集', '旧衣服回收价格通常按公斤计价，因衣物品类、分拣等级与地区行情而异，回收价波动直接影响产业链各环节的利润空间。'],
        // ---- 分拣批发 ----
        ['旧衣分拣', '分拣批发', '旧衣分拣是回收后的旧衣服按品类、材质、成色分级的过程，分拣厂的分拣能力与自动化水平决定货源的商品化价值。'],
        ['旧衣统货', '分拣批发', '统货指未经精细分级的混合旧衣服，按吨或按柜计价批发，是旧衣出口与国内批发市场最常见的交易形态。'],
        ['旧衣原料', '分拣批发', '旧衣原料指分拣后可用于再加工或出口的旧衣服货源，等级从大白、二白到统货，是纺织再生与出口贸易的上游原料。'],
        ['二手服装批发', '分拣批发', '二手服装批发是旧衣分拣后按等级打包、批发给下游零售商与出口商的交易环节，批发市场集中在广州等货源集散地。'],
        ['鞋子统货', '分拣批发', '鞋子统货指混合回收的旧鞋不分级批量交易，主要出口非洲与东南亚市场，按吨或按柜计价，行情随季节与目的国需求波动。'],
        ['二手鞋', '分拣批发', '二手鞋是旧衣回收体系里的独立品类，经分拣、清洁、配对后按等级出口或进入国内二手市场，品牌鞋单件溢价明显。'],
        ['二手包', '分拣批发', '二手包（箱包）与旧衣同源回收，精品包单件溢价高、统货包按吨出口，是回收行业里利润相对较高的品类。'],
        ['库存服装', '分拣批发', '库存服装是工厂尾单、外贸剩余与渠道退市的全新存货，与旧衣回收并列构成二手服装货源的两大来源。'],
        ['服装尾货', '分拣批发', '服装尾货指品牌订单剩余、取消订单与瑕疵品，批量流入尾货市场按份或按吨批发，价格远低于正价货源。'],
        // ---- 出口贸易 ----
        ['旧衣出口', '出口贸易', '旧衣出口是中国旧衣产业链的核心环节，货源经分拣、打包装柜后销往非洲、东南亚与中东市场，出口量与柜价是行业核心数据。'],
        ['二手服装出口', '出口贸易', '二手服装出口需符合目的国检验检疫与进口政策要求，出口量、装柜单价与目的国到岸行情是出口商每天跟踪的变量。'],
        ['纺织品出口', '出口贸易', '纺织品出口涵盖服装、家纺与纤维原料的对外贸易，海关总署的月度出口数据是观察纺织行业景气度的风向标。'],
        ['旧衣非洲市场', '出口贸易', '非洲是中国旧衣出口的最大目的地，加纳、肯尼亚、尼日利亚等市场的进货政策、汇率与到岸行情直接影响出口利润。'],
        // ---- 进口市场 ----
        ['非洲二手服装', '进口市场', '非洲二手服装市场（当地称 mitumba／米通巴）吸收了全球大部分旧衣出口量，进口政策与税收变化会重塑全球贸易流向。'],
        ['二手服装进口', '进口市场', '各国对二手服装进口的政策差异极大，从全面禁止到低关税放开，进口限制是旧衣国际贸易最大的政策变量。'],
        // ---- 循环利用 ----
        ['废旧纺织品', '循环利用', '废旧纺织品是废弃服装与家纺的统称，中国每年产生量以千万吨计，回收利用率是循环经济政策考核的核心指标。'],
        ['纺织品回收', '循环利用', '纺织品回收包括旧衣再流通与纤维再利用两条路径，是纺织行业循环经济与减碳的基础环节。'],
        ['再生纤维', '循环利用', '再生纤维由废旧纺织品经物理或化学工艺开松、再造制成，纤维到纤维（fiber-to-fiber）回收是当前行业技术前沿。'],
        ['化学回收', '循环利用', '化学回收通过解聚等工艺把废旧纺织品还原成原料级纤维，能处理混纺面料，被视为再生纤维的下一代技术路线。'],
        ['快时尚', '循环利用', '快时尚的高频上新模式是纺织废弃物增长的主要推手，各国正通过生产者责任延伸等政策约束快时尚品牌承担回收责任。'],
        ['可持续时尚', '循环利用', '可持续时尚涵盖二手转售、租赁、修补与环保面料等实践，是时尚行业减碳与循环经济的主要叙事。'],
        ['废布抹布', '循环利用', '废布、抹布（擦机布）由废旧纺织品开松裁切制成，是工业擦拭耗材，构成废纺利用里最成熟的商业化路径之一。'],
        // ---- 原料行情 ----
        ['羽绒价格', '原料行情', '羽绒价格按绒子含量分档（如 90% 白鸭绒），每日行情由主产区报价决定，直接影响羽绒服与再生羽绒产业链成本。'],
        ['棉花价格', '原料行情', '棉花价格（郑棉、美棉期货）是纺织原料行情的风向标，间接影响二手棉衣与棉纺废料的回收定价。'],
        ['再生羽绒', '原料行情', '再生羽绒从回收的旧羽绒服中提取，经分拣、清洗、消毒后重新填充，价格低于新绒，是循环时尚的重要原料。'],
        // ---- 综合资讯 ----
        ['生产者责任延伸', '综合资讯', '生产者责任延伸（EPR）要求品牌对产品全生命周期负责，欧盟已把纺织品纳入 EPR 立法进程，将重塑旧衣回收的资金来源。'],
        ['二手交易平台', '综合资讯', '二手交易平台（如 Vinted、ThredUp、闲鱼）撮合个人闲置交易，平台财报与运营数据是二手市场景气度的晴雨表。'],
        ['二手市场', '综合资讯', '二手市场泛指二手服装、鞋包等闲置商品的线下市集与线上平台，全球交易规模持续增长，新旧程度与定价体系日趋成熟。'],
    ];

    /** 建表 + 播种（幂等，门闩各管一件事）；首页/话题页首次访问时自动完成 */
    public static function ensure_ready(): void
    {
        if ((int)setting('topic_tags_schema_version', '0') < self::SCHEMA_VERSION) {
            db()->exec('CREATE TABLE IF NOT EXISTS plugin_topic_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                keyword TEXT NOT NULL UNIQUE,
                chain TEXT NOT NULL DEFAULT \'\',
                summary TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'active\',
                position INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )');
            save_settings_values(['topic_tags_schema_version' => (string)self::SCHEMA_VERSION]);
        }
        if ((int)setting('topic_tags_seed_version', '0') < self::SEED_VERSION) {
            $now = now();
            $st = db()->prepare('INSERT OR IGNORE INTO plugin_topic_tags
                (keyword, chain, summary, status, position, created_at, updated_at)
                VALUES (?, ?, ?, \'active\', ?, ?, ?)');
            foreach (self::SEEDS as $i => [$keyword, $chain, $summary]) {
                $st->execute([$keyword, $chain, $summary, $i, $now, $now]);
            }
            save_settings_values(['topic_tags_seed_version' => (string)self::SEED_VERSION]);
        }
    }

    /** 按关键词取启用中的话题定义 */
    public static function find_active(string $keyword): ?array
    {
        $st = db()->prepare("SELECT * FROM plugin_topic_tags WHERE keyword = ? AND status = 'active'");
        $st->execute([$keyword]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** 全部启用话题（sitemap、侧栏用） */
    public static function active(): array
    {
        return db()->query("SELECT * FROM plugin_topic_tags WHERE status = 'active' ORDER BY position, id")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 全站 footer 用：排位最前的一组话题关键词 */
    public static function hot(int $limit = 10): array
    {
        return array_slice(array_column(self::active(), 'keyword'), 0, max(1, $limit));
    }

    /** 主题页「相关话题」：标题里出现过的话题词（标题是最强的主题信号，正文匹配留待以后需要时再做） */
    public static function for_topic_title(string $title, int $limit = 6): array
    {
        if (trim($title) === '') return [];
        $hits = [];
        foreach (self::active() as $row) {
            if (mb_strpos($title, (string)$row['keyword']) !== false) $hits[] = (string)$row['keyword'];
            if (count($hits) >= max(1, $limit)) break;
        }
        return $hits;
    }

    /** 侧栏话题组：chain => 行列表（首页侧栏的「产业链话题」入口） */
    public static function chips(): array
    {
        $groups = [];
        foreach (self::active() as $row) {
            $groups[(string)$row['chain']][] = ['keyword' => (string)$row['keyword']];
        }
        return $groups;
    }

    /** 同链条的相邻话题（话题页底部「相关话题」内链） */
    public static function siblings(int $id, string $chain, int $limit = 14): array
    {
        $st = db()->prepare("SELECT keyword FROM plugin_topic_tags
            WHERE status = 'active' AND chain = ? AND id != ? ORDER BY position, id LIMIT " . max(1, $limit));
        $st->execute([$chain, $id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * 话题页主题列表：全文索引命中该关键词的主题，按时间倒序（话题页是「最新动态」入口）。
     * 行结构与首页列表一致，可直接交给 ui.topic_list_row；脱敏口径与列表页相同。
     */
    public static function topic_rows(string $keyword, int $limit): array
    {
        self::ensure_ready();
        $limit = min(max(1, $limit), self::LIST_LIMIT_MAX);
        $ids = [];
        if (SearchIndex::available()) {
            $plan = SearchIndex::match_plan($keyword);
            if ($plan !== null) {
                $st = db()->prepare('SELECT rowid FROM plugin_fts_topics WHERE plugin_fts_topics MATCH ?');
                $st->execute([$plan['expr']]);
                // rowid 即主题 id（自增单调），取末尾 200 个即最新候选，避开超长 IN 列表
                $ids = array_slice(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), -self::FTS_CANDIDATES);
            }
        }
        if ($ids) {
            $rows = Topic::whereIn('id', $ids)->orderByDesc('id')->limit($limit)->get()->map->toArray()->all();
        } else {
            // FTS 不可用或关键词构不成表达式：标题 LIKE 兜底
            $rows = Topic::where('title', 'LIKE', search_like_pattern($keyword))
                ->orderByDesc('id')->limit($limit)->get()->map->toArray()->all();
        }
        $rows = attach_topic_list_users($rows);
        foreach ($rows as &$t) {
            $t['time'] = (int)(($t['last_reply_at'] ?? 0) ?: ($t['created_at'] ?? 0));
            $t['forum'] = forum_by_id((int)$t['forum_id']) ?: ['id' => 0, 'name' => ''];
            // 收费模式版块：列表标题同样打码（与首页/版块页同口径）
            if (!can_manage() && (int)($t['forum']['paid_mode'] ?? 0) === 1) $t['title'] = mask_contacts((string)$t['title']);
        }
        unset($t);
        return $rows;
    }
}
