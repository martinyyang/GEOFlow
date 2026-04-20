-- GEOFlow 后台配置 SQL 脚本（最终版本）
-- 用于快速配置站点设置、AI模型、素材库等

-- 1. 配置站点设置
INSERT INTO settings (key, value)
VALUES
    ('site_name', 'Fetch China - 您在中国的眼睛和双手'),
    ('site_description', 'Fetch China是一个专业的中国代购服务平台，帮助全球用户从淘宝、天猫、京东等中国电商平台购买商品并配送到世界各地。'),
    ('site_keywords', '中国代购,淘宝代购,天猫代购,京东代购,1688代购,中国购物,海外代购'),
    ('site_url', 'http://localhost:18080'),
    ('articles_per_page', '10'),
    ('auto_publish', '0'),
    ('enable_comments', '0'),
    ('default_author_id', '1'),
    ('timezone', 'Asia/Shanghai'),
    ('language', 'zh-CN')
ON CONFLICT (key)
DO UPDATE SET value = EXCLUDED.value;

-- 2. 配置AI模型（DeepSeek）
INSERT INTO ai_models (name, model_id, api_url, api_key, model_type, status, created_at, updated_at)
VALUES
    ('DeepSeek Chat', 'deepseek-chat', 'https://api.deepseek.com/v1/chat/completions', 'YOUR_DEEPSEEK_API_KEY_HERE', 'chat', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT DO NOTHING;

-- 3. 创建文章分类
INSERT INTO categories (name, slug, description, created_at)
VALUES
    ('公告', 'announcements', '平台公告和重要通知', CURRENT_TIMESTAMP),
    ('购物指南', 'shopping-guide', '中国购物平台使用指南', CURRENT_TIMESTAMP),
    ('产品推荐', 'product-recommendations', '热门产品推荐和评测', CURRENT_TIMESTAMP),
    ('物流更新', 'shipping-updates', '物流信息和配送更新', CURRENT_TIMESTAMP),
    ('常见问题', 'faq', '常见问题解答', CURRENT_TIMESTAMP)
ON CONFLICT (slug)
DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description;

-- 4. 创建标题库并添加标题
DO $$
DECLARE
    v_title_library_id INTEGER;
BEGIN
    -- 创建标题库
    INSERT INTO title_libraries (name, description, generation_type, created_at, updated_at)
    VALUES ('Fetch China 标题库', '用于生成Fetch China博客文章的标题库', 'manual', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ON CONFLICT DO NOTHING
    RETURNING id INTO v_title_library_id;

    -- 如果已存在，获取ID
    IF v_title_library_id IS NULL THEN
        SELECT id INTO v_title_library_id FROM title_libraries WHERE name = 'Fetch China 标题库' LIMIT 1;
    END IF;

    -- 添加标题
    INSERT INTO titles (library_id, title, created_at)
    VALUES
        (v_title_library_id, '如何在淘宝上找到最优惠的商品', CURRENT_TIMESTAMP),
        (v_title_library_id, '中国服装尺码对照指南', CURRENT_TIMESTAMP),
        (v_title_library_id, '选择合适的国际物流方式', CURRENT_TIMESTAMP),
        (v_title_library_id, '淘宝购物常见问题解答', CURRENT_TIMESTAMP),
        (v_title_library_id, '如何验证中国商品的质量', CURRENT_TIMESTAMP),
        (v_title_library_id, '1688批发平台购物指南', CURRENT_TIMESTAMP),
        (v_title_library_id, '京东vs天猫：哪个平台更适合你', CURRENT_TIMESTAMP),
        (v_title_library_id, '中国电商节日购物攻略', CURRENT_TIMESTAMP),
        (v_title_library_id, '如何避免代购陷阱', CURRENT_TIMESTAMP),
        (v_title_library_id, '热门中国品牌推荐', CURRENT_TIMESTAMP)
    ON CONFLICT DO NOTHING;

    -- 更新标题库的标题数量
    UPDATE title_libraries
    SET title_count = (SELECT COUNT(*) FROM titles WHERE library_id = v_title_library_id)
    WHERE id = v_title_library_id;
END $$;

-- 5. 创建图片库（注意：images表需要实际文件，这里只创建库）
INSERT INTO image_libraries (name, description, created_at, updated_at)
VALUES ('Fetch China 图片库', '用于文章配图的图片库（需要手动上传图片）', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT DO NOTHING;

-- 6. 创建知识库
DO $$
DECLARE
    v_knowledge_content TEXT;
BEGIN
    v_knowledge_content := '# Fetch China 服务介绍

## 什么是Fetch China？

Fetch China是一个专业的中国代购服务平台，我们是您在中国的眼睛和双手。我们帮助全球用户从中国电商平台（如淘宝、天猫、京东、1688等）购买商品，并配送到世界各地。

## 服务流程

1. **您选择** - 在任何中国电商网站找到想要的商品，发送链接给我们
2. **我们代购** - 我们的中国团队代您购买商品
3. **质量检查** - 我们检查商品质量并发送照片给您确认
4. **我们配送** - 选择您喜欢的物流方式，我们处理剩余事宜

## 为什么使用代购服务？

直接从中国平台购物可能面临以下挑战：

- **语言障碍** - 大多数平台仅支持中文
- **支付问题** - 许多网站只接受中国支付方式（支付宝、微信支付）
- **物流限制** - 大多数卖家不提供国际配送
- **质量担忧** - 难以从海外验证产品质量

Fetch China为您解决所有这些问题。

## 支持的平台

- 淘宝 (Taobao)
- 天猫 (Tmall)
- 京东 (JD.com)
- 1688 (批发平台)
- 其他中国电商平台

## 物流方式

我们提供多种物流选择：
- 标准物流（经济实惠）
- 快速物流（3-7天）
- 特快物流（1-3天）

## 费用说明

- 商品费用：实际购买价格
- 服务费：商品价格的5-10%
- 国际运费：根据重量和目的地计算
- 可选增值服务：加固包装、保险等

## 常见问题

### 如何下单？
1. 找到您想要的商品链接
2. 联系我们的客服
3. 提供商品链接和数量
4. 我们会给您报价
5. 确认后我们开始代购

### 支付方式
我们接受：
- PayPal
- 信用卡
- 银行转账
- Western Union

### 配送时间
- 标准物流：15-30天
- 快速物流：7-15天
- 特快物流：3-7天

### 退换货政策
- 商品到达前的质量问题：免费退换
- 商品到达后：根据卖家政策处理
- 我们会协助您处理所有退换货事宜';

    INSERT INTO knowledge_bases (name, description, content, file_type, character_count, word_count, created_at, updated_at)
    VALUES (
        'Fetch China 知识库',
        'Fetch China服务介绍和常见问题知识库',
        v_knowledge_content,
        'markdown',
        LENGTH(v_knowledge_content),
        array_length(string_to_array(v_knowledge_content, ' '), 1),
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    )
    ON CONFLICT DO NOTHING;
END $$;

-- 7. 创建提示词模板
INSERT INTO prompts (name, type, content, variables, created_at, updated_at)
VALUES
    ('博客文章生成', 'article', '你是Fetch China的内容编辑。请根据标题"{title}"撰写一篇专业的博客文章。

要求：
1. 文章应该对读者有实际帮助
2. 包含具体的步骤或建议
3. 语气友好、专业
4. 字数800-1200字
5. 使用Markdown格式
6. 包含相关的实例或案例

请参考以下知识库内容：
{knowledge}

请开始撰写文章：', 'title,knowledge', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('产品推荐文章', 'article', '你是Fetch China的产品推荐专家。请根据标题"{title}"撰写一篇产品推荐文章。

要求：
1. 介绍产品的特点和优势
2. 说明为什么推荐这个产品
3. 提供购买建议
4. 字数600-1000字
5. 使用Markdown格式

请参考以下知识库内容：
{knowledge}

请开始撰写：', 'title,knowledge', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('标题生成', 'title', '你是一个专业的内容营销专家。请根据以下关键词生成10个吸引人的博客文章标题。

关键词：{keywords}

要求：
1. 标题要吸引眼球
2. 包含关键词
3. 适合SEO优化
4. 每个标题一行
5. 不要编号

请生成标题：', 'keywords', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT DO NOTHING;

-- 配置完成提示
SELECT '=== GEOFlow 配置完成 ===' as message;
SELECT '✓ 站点设置已配置' as step1;
SELECT '✓ AI模型已添加（DeepSeek Chat - 需要更新API密钥）' as step2;
SELECT '✓ 5个文章分类已创建' as step3;
SELECT '✓ 标题库已创建（包含10个标题）' as step4;
SELECT '✓ 图片库已创建（需要手动上传图片）' as step5;
SELECT '✓ 知识库已创建' as step6;
SELECT '✓ 3个提示词模板已创建' as step7;
SELECT '' as blank;
SELECT '下一步操作：' as next_steps;
SELECT '1. 访问后台: http://localhost:18080/admin/' as action1;
SELECT '2. 登录账号: admin / admin888' as action2;
SELECT '3. 前往 AI配置中心 -> AI模型管理，更新DeepSeek API密钥' as action3;
SELECT '4. 前往 素材管理 -> 图片库，上传一些图片' as action4;
SELECT '5. 前往 任务管理，创建第一个任务测试系统' as action5;
