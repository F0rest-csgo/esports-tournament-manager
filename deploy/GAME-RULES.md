# VALORANT 与 CS2 小分预设

核对日期：2026-11-03。此处预设的是地图回合计分和加时终局判断；BO 场次由赛事每个阶段自行设置，不绑定游戏，不改变自定义的赛事赛制。暂不提供第三个游戏或自定义加时参数。

## VALORANT

采用正式赛事的 Tournament / Overtime: Win by Two：

- 常规每半场 12 回合；先取得 13 回合，且对手不超过 11 回合，获胜。
- 12:12 进入加时；每回合交换攻守，每组两个回合需由同一队全部取得胜利，形成净胜 2 回合；否则继续加时。
- 正式赛事预设不接受排位赛投票平局。
- 可录入 13:0～11、14:12、15:13、16:14 等终局；13:12、14:13、16:13 会被拒绝。
- 不存在“加时每半场局数”输入框，后台也不保存无关的 `block_size` 参数。

来源：[Riot 2026 VCT EMEA Official Competition Ruleset，第 4.6.2 条](https://cdn.sanity.io/files/dsfx7636/news_live/e069feed802c0aa6e77797e7727610c3a6f299cd.pdf)，经 [Riot EMEA Competitive Operations](https://www.emea-competitiveops.com/) 官方规则入口核对。该条明确使用 Tournament 模式并启用 Win by Two。

## CS2

采用 MR12 常规赛和持续 MR3 加时：

- 常规最多 24 回合，每半场 12 回合，先取得 13 回合获胜。
- 12:12 进入 6 回合加时，每半场 3 回合；该加时先取得 4 回合者获胜。
- 15:15 则进入下一次加时，继续同一规则，不接受平局。
- 可录入 13:0～11、16:12～14、19:15～17、22:18～20 等终局；13:12、14:12、15:13、16:15、18:16 会被拒绝。
- “每半场 3 回合”是 CS2 预设内的规则，用户无需另填参数。

来源：[Valve 官方《Your Time is Now》公告（Steam 官方公告流）](https://store.steampowered.com/news/posts/?appids=730&enddate=1694214924&feed=steam_community_announcements)，明确说明 CS2 常规最多 24 回合，平局采用 6 回合加时，适用于 Major 等模式。终局分数按 MR3 每次加时先取 4 回合计算；不采用匹配模式可能存在的加时上限或平局处理。

游戏预设只负责比分计算和有效性检查，不替代赛事主办方对地图池、经济设置、暂停、裁判判罚等赛务规则的配置。
