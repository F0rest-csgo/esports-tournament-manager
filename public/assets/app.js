'use strict';
(() => {
const body=document.body, mode=body.dataset.mode;
if(!mode)return;
const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>[...r.querySelectorAll(s)];
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const formats={single:'单败淘汰赛',double:'双败淘汰赛',round_robin:'单循环',double_round_robin:'双循环',swiss:'瑞士轮'};
const statuses={scheduled:'未开始',live:'进行中',completed:'已结束',postponed:'已延期',cancelled:'已取消',inactive:'待定',pending:'待开始',running:'进行中',active:'开放中',disabled:'已停用',archived:'已归档'};
const actions={attachment_upload:'上传附件',attachment_visibility:'修改附件可见范围',attachment_delete:'删除附件',create:'创建赛事',settings:'更新赛事设置',teams_add:'批量添加队伍',team_edit:'修改队伍',team_delete:'删除队伍',stage_save:'修改赛制',stage_draw:'调整分组与首轮对阵',generate:'生成赛程',stage_reset:'重置阶段',swiss_next:'生成瑞士轮',swiss_undo:'撤回瑞士轮',match_edit:'修改比赛与比分',batch:'批量修改比赛',advance:'锁定晋级',password:'重置管理密码',status:'修改赛事状态',logo_upload:'更新赛事 Logo',logo_delete:'删除赛事 Logo',admin_init:'初始化管理员'};
let csrf=$('meta[name="csrf-token"]')?.content||'', api=mode==='login'?body.dataset.api:(mode==='global'?'/api/admin':'/api/tournaments/'+body.dataset.slug);
let data=null, page='dashboard', tab='overview', selectedStage='', swissRound='', selected=new Set(), filters={stage:'',round:'',group:'',status:'',team:'',sort:'number'}, logCursor='',logs=[],preview=null;
let toastTimer; const previewEdges=new Map();
function toast(message,error=false){const t=$('#toast');if(!t)return;t.textContent=message;t.className='visible'+(error?' error':'');clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.className='',6500);}
async function request(path='',payload,form=false){const options={credentials:'same-origin',headers:{Accept:'application/json'}};if(payload!==undefined){options.method='POST';options.headers['X-CSRF-Token']=csrf;if(form)options.body=payload;else{options.headers['Content-Type']='application/json';options.body=JSON.stringify(payload);}}const r=await fetch(api+path,options);let json;try{json=await r.json();}catch{throw new Error('服务器返回异常，请检查部署配置。');}if(!r.ok)throw new Error(json.error||'请求失败');return json;}
function button(text,action,extra='',kind='secondary'){return `<button class="button ${kind}" type="button" data-action="${action}" ${extra}>${text}</button>`;}
function liveBadge(){return '<span class="live-marker"><span class="live-marker-dot" aria-hidden="true"></span>LIVE</span>';}
function pill(status){return status==='live'?liveBadge():`<span class="status ${esc(status)}">${esc(statuses[status]||status)}</span>`;}
function resolved(m){return m.status==='completed'||(m.status==='cancelled'&&+m.forfeit_team_id&&+m.winner_id);}
function matchScore(m){
    if(+m.is_bye)return null;
    const first=m.games?.find(g=>+g.game_number===1);
    if(+m.best_of===1&&first)return [first.team_a_score,first.team_b_score];
    if((data.tournament.score_mode==='detailed'||+m.forfeit_team_id)&&m.score_a!==null&&m.score_a!==undefined)return [m.score_a,m.score_b];
    return null;
}
function linkedDescription(value){
    const text=String(value||'');const urlPattern=/https?:\/\/[^\s<>"'\u0000-\u001f\u007f]+/gi;let html='',cursor=0;
    for(const match of text.matchAll(urlPattern)){
        html+=esc(text.slice(cursor,match.index));let raw=match[0],tail='';
        while(/[.,;:!?，。；：！？、）】》]$/.test(raw)||(raw.endsWith(')')&&(raw.match(/\)/g)||[]).length>(raw.match(/\(/g)||[]).length)) {tail=raw.slice(-1)+tail;raw=raw.slice(0,-1);}
        try{const url=new URL(raw);html+=['http:','https:'].includes(url.protocol)&&url.hostname?`<a href="${esc(url.href)}" target="_blank" rel="noopener noreferrer">${esc(raw)}</a>`:esc(raw);}catch{html+=esc(raw);}
        html+=esc(tail);cursor=match.index+match[0].length;
    }
    return html+esc(text.slice(cursor));
}
function matchDetail(id){
    const m=data.matches.find(m=>+m.id===+id);if(!m){closeModal();return;}
    const score=matchScore(m),numbers=[...new Set([...(m.games||[]).map(g=>+g.game_number),...(m.maps||[]).map(g=>+g.game_number)])].sort((a,b)=>a-b);
    openModal('比赛详情 #'+String(m.match_number).padStart(3,'0'),`<div class="detail-meta">${pill(m.effective_status)}<span>${esc(stageName(m.stage_id))} · 第 ${m.round_number} 轮 · BO${m.best_of}</span><span>${esc(localTime(m.scheduled_at))} · ${esc(data.tournament.timezone)}</span></div><div class="detail-versus"><div>${teamHtml(m.team_a_id,m.winner_id&&+m.winner_id===+m.team_a_id)}</div><strong class="detail-score">${+m.is_bye?'轮空':score?score.map(esc).join(' : '):'VS'}</strong><div>${teamHtml(m.team_b_id,m.winner_id&&+m.winner_id===+m.team_b_id)}</div></div>${+m.forfeit_team_id?`<div class="notice forfeit-notice">${esc(teamName(m.forfeit_team_id))} 被判负，${esc(teamName(m.winner_id))} 按 13:0 判胜。${+m.best_of>1?'整场大比分 '+esc(m.score_a)+':'+esc(m.score_b)+'。':''}比赛状态为已取消，判罚结果计入排名与晋级。</div>`:''}<h3>${+m.forfeit_team_id?'判罚比分与地图安排':'地图与比分'}</h3>${numbers.length?`<div class="table-scroll"><table><thead><tr><th>局次</th><th>地图</th><th>${esc(teamName(m.team_a_id))}</th><th>${esc(teamName(m.team_b_id))}</th></tr></thead><tbody>${numbers.map(n=>{const g=m.games.find(g=>+g.game_number===n),map=m.maps?.find(g=>+g.game_number===n);return `<tr><td>${+m.forfeit_team_id?'判罚第 '+n+' 局':'地图 '+n}</td><td>${esc(map?.map_name||g?.map_name||'未设置')}</td><td class="mono">${g?esc(g.team_a_score):'—'}</td><td class="mono">${g?esc(g.team_b_score):'—'}</td></tr>`;}).join('')}</tbody></table></div>`:'<p class="fine">尚未填写地图或逐局比分。</p>'}<h3>比赛说明</h3><div class="match-description">${m.description?linkedDescription(m.description):'<span class="fine">暂无比赛说明</span>'}</div><div class="modal-actions">${button('关闭','close')}${mode!=='public'?button('编辑比赛','match-edit',`data-id="${m.id}"`,'primary'):''}</div>`);
    $('#modal').dataset.matchDetail=String(m.id);
}
function empty(text='暂无数据'){return `<div class="empty"><div class="empty-symbol">⌁</div>${esc(text)}</div>`;}
function heading(title,desc='',tools=''){return `<div class="page-heading"><div><div class="eyebrow">TOURNAMENT / CONSOLE</div><h1>${esc(title)}</h1><p>${esc(desc)}</p></div><div class="toolbar">${tools}</div></div>`;}
function option(v,label,current){return `<option value="${esc(v)}" ${String(v)===String(current)?'selected':''}>${esc(label)}</option>`;}
function field(label,name,value='',type='text',extra=''){return `<label>${label}<input name="${name}" type="${type}" value="${esc(value)}" ${extra}></label>`;}
function select(label,name,choices,value=''){return `<label>${label}<select name="${name}">${Object.entries(choices).map(([v,l])=>option(v,l,value)).join('')}</select></label>`;}
function check(label,name,value){return `<label class="check"><input type="checkbox" name="${name}" ${value?'checked':''}>${label}</label>`;}
function formEnd(text='保存修改'){return `<div class="form-error" role="alert"></div><button class="button" type="submit">${text}</button>`;}
function stageName(id){return data.stages.find(s=>+s.id===+id)?.name||'阶段待定';}
function team(id){return data?.teams?.find(t=>+t.id===+id);}
function teamName(id){return team(id)?.name||'TBD';}
function teamHtml(id,win=false){const logo=id&&data.tournament.logo_path;return `<span class="team-name ${win?'winner-text':''}">${logo?`<img class="team-logo" src="${esc(logo)}" alt="">`:''}${esc(teamName(id))}</span>`;}
function localTime(value,input=false){if(!value)return input?'':'TBA';const d=new Date(value.replace(' ','T')+'Z');if(Number.isNaN(d.valueOf()))return input?'':'TBA';const parts=new Intl.DateTimeFormat('sv-SE',{timeZone:data?.tournament?.timezone||'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false}).format(d);return input?parts.replace(' ','T'):parts;}
function currentStage(){return data.stages.find(s=>String(s.id)===String(selectedStage))||data.stages.find(s=>s.status==='running')||data.stages.find(s=>s.status==='pending')||data.stages.at(-1);}
function openModal(title,html){const dialog=$('#modal');delete dialog.dataset.matchDetail;$('#modal-body').innerHTML=`<div class="modal-heading"><h2>${esc(title)}</h2><button class="close-button" type="button" data-action="close" aria-label="关闭">×</button></div>${html}`;if(!dialog.open)dialog.showModal();}
function closeModal(){const dialog=$('#modal');if(dialog){delete dialog.dataset.matchDetail;if(dialog.open)dialog.close();}}
async function confirmAction(title,text,run){openModal(title,`<p class="muted">${esc(text)}</p><div class="form-error" role="alert"></div><div class="modal-actions">${button('取消','close')}${button('确认执行','confirm','','primary')}</div>`);$('[data-action="confirm"]').onclick=async e=>{e.currentTarget.disabled=true;try{await run();closeModal();}catch(err){$('.form-error',$('#modal')).textContent=err.message;e.target.disabled=false;}};}
async function mutate(action,payload={}){const result=await request('/'+action,payload);if(result.slug&&result.slug!==body.dataset.slug&&mode==='tournament'){location.href='/tournaments/'+result.slug+'/admin';return;}selected.clear();toast(result.message||'操作成功');await load();return result;}
if(mode==='login'){$('#login-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget,b=$('button',f);b.disabled=true;$('.form-error',f).textContent='';try{const r=await request('/login',Object.fromEntries(new FormData(f)));location.href=r.redirect;}catch(err){$('.form-error',f).textContent=err.message;b.disabled=false;}});return;}
const nav=mode==='global'?[['dashboard','▦','赛事控制台'],['logs','≡','全局操作日志']]:[['dashboard','▦','仪表盘'],['settings','⚙','赛事设置'],['teams','♧','参赛队伍'],['formats','◇','赛制设置'],['schedule','▤','分组与赛程'],['matches','⚑','比赛管理'],['advance','↗','排名与晋级'],['logs','≡','操作日志']];
if(mode!=='public')$('#side-nav').innerHTML=nav.map(([p,i,l])=>`<button type="button" data-page="${p}"><span class="nav-icon">${i}</span>${l}</button>`).join('');
async function load(){data=await request('/state');if(mode==='global')renderGlobal();else if(mode==='public')renderPublic();else renderAdmin();if($('#modal')?.open&&$('#modal').dataset.matchDetail)matchDetail($('#modal').dataset.matchDetail);}
function stat(label,value,note,blue=false){return `<div class="stat ${blue?'blue':''}"><div class="stat-label">${label}</div><div class="stat-value">${value}</div><div class="stat-note">${note}</div></div>`;}
function renderGlobal(){markNav();if(page==='logs'){renderLogs();return;}const ts=data.tournaments;$('#content').innerHTML=heading('赛事控制台','管理所有赛事与独立访问权限。',button('＋ 创建赛事','create','','primary'))+`<div class="stats">${stat('赛事总数',ts.length,'全部独立赛事',true)}${stat('开放赛事',ts.filter(t=>t.status==='active').length,'当前开放访问')}${stat('参赛队伍',ts.reduce((n,t)=>n+(+t.team_count),0),'所有赛事合计')}${stat('已归档',ts.filter(t=>t.status==='archived').length,'保留完整比赛记录')}</div><section class="card"><div class="section-heading"><h2>全部赛事</h2><span>${ts.length} 个赛事</span></div>${ts.length?`<div class="table-scroll"><table><thead><tr><th>赛事</th><th>专属地址</th><th>队伍</th><th>状态</th><th>操作</th></tr></thead><tbody>${ts.map(t=>`<tr><td class="wrap"><strong>${esc(t.name)}</strong><div class="fine">${esc(t.timezone)}</div></td><td><a href="/tournaments/${esc(t.slug)}" target="_blank" rel="noopener">${esc(t.slug)} ↗</a></td><td>${t.team_count}</td><td>${pill(t.status)}</td><td><div class="toolbar"><a class="button small secondary" href="/tournaments/${esc(t.slug)}/admin">管理赛事</a>${button('密码','password',`data-id="${t.id}"`,'small secondary')}${button('状态','tournament-status',`data-id="${t.id}"`,'small secondary')}</div></td></tr>`).join('')}</tbody></table></div>`:empty('还没有赛事。创建第一个赛事，开始编排赛程。')}</section>`;}
function markNav(){$$('#side-nav button').forEach(b=>b.classList.toggle('active',b.dataset.page===page));}
function renderAdmin(){markNav();const t=data.tournament;if(page==='logs'){renderLogs();return;}if(page==='settings'){$('#content').innerHTML=heading('赛事设置','管理赛事信息、比分规则、Logo 和赛事附件。')+`<div class="grid-two"><section class="card"><h2>基本信息与比赛规则</h2><form data-form="settings">${settingsFields(t)}${formEnd()}</form></section><section class="card"><h2>赛事 Logo</h2><p class="fine">设置后所有队伍统一使用此 Logo。支持 PNG、JPEG、WebP，最大 10 MB、4096×4096。</p>${t.logo_path?`<img class="event-logo" src="${esc(t.logo_path)}" alt="赛事 Logo"><p>${button('删除 Logo','logo-delete','','small danger')}</p>`:empty('尚未设置 Logo')}<form data-form="logo"><label>选择图片<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required></label>${formEnd('上传 Logo')}</form></section></div>`;mountAttachmentPanel($('#content'));return;}
if(page==='teams'){renderTeamsAdmin();return;}if(page==='formats'){renderFormats();return;}if(page==='schedule'){renderSchedule();return;}if(page==='matches'){$('#content').innerHTML=heading('比赛管理','编辑对阵、小局比分与比赛时间。',button('批量设置','batch','','primary'))+`<section class="card">${matchFilters()}${matchesTable(filteredMatches(),true)}</section>`;return;}if(page==='advance'){renderAdvance();return;}
const ms=data.matches.filter(m=>+m.is_active&&!+m.is_bye),done=ms.filter(resolved).length,live=ms.filter(m=>m.effective_status==='live').length;
$('#content').innerHTML=heading(t.name,'赛事概况与当前比赛进度。',`<a class="button secondary" href="/tournaments/${esc(t.slug)}" target="_blank" rel="noopener">查看公开页面 ↗</a>`)+`<div class="stats">${stat('参赛队伍',data.teams.length,'独立种子排序',true)}${stat('比赛总数',ms.length,`${done} 场已完成`)}${stat('正在进行',live,'按赛事时间自动更新')}${stat('赛事阶段',data.stages.length,`${data.stages.filter(s=>s.status==='completed').length} 个阶段已完成`)}</div><div class="grid-two"><section class="card"><div class="section-heading"><h2>赛事进度</h2><span>${esc(t.timezone)}</span></div>${data.stages.map((s,i)=>`<div class="list-row"><div class="toolbar"><span class="step-number">0${i+1}</span><div><strong>${esc(s.name)}</strong><div class="fine">${formats[s.format_type]} · ${data.matches.filter(m=>+m.stage_id===+s.id&&+m.is_active).length} 场比赛</div></div></div>${pill(s.status)}</div>`).join('')}<div class="notice">先添加队伍、设置赛制，再生成赛程。双阶段赛事通过晋级预览锁定第二阶段。</div></section><section class="card"><div class="section-heading"><h2>赛事访问</h2><span>专属链接</span></div><label>公开页面<input readonly value="${esc(location.origin+'/tournaments/'+t.slug)}"></label><label>赛事管理<input readonly value="${esc(location.origin+'/tournaments/'+t.slug+'/admin')}"></label><p class="fine">页面每 20 秒同步比赛数据。所有队伍共用赛事 Logo，所有时间以 ${esc(t.timezone)} 显示。</p></section></div><section class="card"><div class="section-heading"><h2>进行中的比赛</h2><span>${live} 场</span></div>${matchesTable(ms.filter(m=>m.effective_status==='live'),true)}</section>`;
}
function gameSummary(game){return game==='valorant'?'VALORANT：每张地图常规先得 13 回合获胜；12:12 后逐回合交换攻守，必须净胜 2 回合，不接受平局。合法终局如 13:11、14:12、15:13。':game==='cs2'?'CS2：MR12，常规先得 13 回合获胜；12:12 后进行 MR3 加时，每半场 3 回合，每个加时阶段先赢 4 回合获胜，打平继续加时。合法终局如 13:11、16:14、19:17。':'请选择游戏，系统将自动使用对应正式比赛的小分与加时规则。';}
const defaultMapPools={cs2:['Ancient','Anubis','Cache','Dust 2','Inferno','Mirage','Nuke'],valorant:[]};
function mapPoolText(game){return game==='cs2'?'默认使用 CS2 官方赛事地图池，可按本赛事实际规则调整。每行填写一个英文地图名。':'VALORANT 不提供默认地图池。请由管理员每行填写一个英文地图名。';}
function settingsFields(t={},create=false){const game=t.game||(create?'cs2':'');const maps=Array.isArray(t.map_pool)?t.map_pool:(defaultMapPools[game]||[]);return `<div class="form-grid">${field('赛事名称','name',t.name||'','text','required maxlength="150"')}<label>URL Slug<input name="slug" value="${esc(t.slug||'')}" placeholder="cs2-campus-2026" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="100" data-slug-field><span class="hint">赛事专属网址的最后一段。只填写小写英文字母、数字和连接符，例如 cs2-campus-2026；同一网站不能重复。请勿填写中文、空格或完整网址。</span><span class="hint">公开链接：<span data-slug-preview>${esc(location.origin+'/tournaments/'+(t.slug||'cs2-campus-2026'))}</span>${create?'':'。修改 Slug 后，原来的赛事链接将失效。'}</span></label>${create?field('赛事独立管理密码','password','','password','required minlength="12" autocomplete="new-password" maxlength="200"'):''}<label class="span-two">赛事介绍<textarea name="description" maxlength="10000">${esc(t.description||'')}</textarea></label>${select('比分模式','score_mode',{detailed:'详细比分模式',winner:'仅记录胜者'},t.score_mode||'detailed')}<label>游戏（小分与加时规则）<select name="game" required>${option('','请选择游戏',game)}${option('valorant','VALORANT',game)}${option('cs2','CS2',game)}</select></label><label class="span-two">本赛事地图池<textarea name="map_pool" data-map-pool data-pool-game="${esc(game)}" rows="7" placeholder="每行一个英文地图名，不会自动填入 VALORANT 地图">${esc(maps.join('\n'))}</textarea><span class="hint" data-map-pool-help>${esc(mapPoolText(game))}</span><span class="hint">每局地图在“编辑比赛”中从此列表选择。修改地图池不会改写已经保存的历史地图名称；地图未填写时也可以先安排比赛。</span></label><div class="span-two">${button('恢复 CS2 默认地图池','map-pool-reset',game==='cs2'?'':'hidden','small secondary')}</div>${field('赛事时区','timezone',t.timezone||'Asia/Shanghai','text','required')}${select('赛事阶段','stage_count',{1:'单阶段',2:'双阶段'},t.stage_count||1)}</div><div class="notice" data-game-summary>${gameSummary(game)}</div><p class="fine">BO 在每个阶段的“赛制设置”中配置。时间按赛事时区输入，统一以 UTC 存储。</p>`;}
function readSettings(f){return Object.fromEntries(new FormData(f));}
function switchMapPool(form,game){const pool=$('[data-map-pool]',form);if(!pool)return;form._poolDrafts||={};form._poolDrafts[pool.dataset.poolGame]=pool.value;pool.value=form._poolDrafts[game]??(defaultMapPools[game]||[]).join('\n');pool.dataset.poolGame=game;$('[data-map-pool-help]',form).textContent=mapPoolText(game);$('[data-action="map-pool-reset"]',form).hidden=game!=='cs2';}

function renderTeamsAdmin(){$('#content').innerHTML=heading('参赛队伍','批量录入队名，手动维护种子排序。')+`<div class="grid-two"><section class="card"><h2>队伍名单 <span class="pill">${data.teams.length}</span></h2>${data.teams.length?`<div class="table-scroll"><table><thead><tr><th>种子</th><th>队伍名称</th><th>操作</th></tr></thead><tbody>${data.teams.map(t=>`<tr><td>#${t.seed}</td><td>${teamHtml(t.id)}</td><td>${button('编辑','team-edit',`data-id="${t.id}"`,'small secondary')} ${button('删除','team-delete',`data-id="${t.id}"`,'small danger')}</td></tr>`).join('')}</tbody></table></div>`:empty('添加参赛队伍开始你的赛事')}</section><section class="card"><h2>批量添加</h2><p class="fine">每行填写一个队名，种子按添加顺序分配。最多 128 支队伍。</p><form data-form="teams_add"><label>队伍名称<textarea name="names" rows="10" placeholder="TEAM ALPHA\nBLUE FOX\nNOVA ESPORTS" required></textarea></label>${formEnd('添加队伍')}</form></section></div>`;}
const groupFormats=['round_robin','double_round_robin'];
const rankingFormats=['round_robin','double_round_robin','swiss'];
function stageOptionBlock(types,content,next=false){return `<div class="form-grid span-two" data-stage-formats="${types.join(' ')}" ${next?'data-stage-next':''}>${content}</div>`;}
function roundBoInfo(format,third=false){
    if(groupFormats.includes(format))return {kinds:[['group','本阶段']],example:'第 1 轮：BO1；第 3 轮：BO3。多个小组的同一轮同时生效。'};
    if(format==='swiss')return {kinds:[['swiss','本阶段']],example:'第 1 轮：BO1；第 5 轮：BO3。只影响本阶段的对应轮次。'};
    if(format==='double')return {kinds:[['upper','胜者组'],['lower','败者组'],['final','总决赛']],example:'例如选择“胜者组 / 第 1 轮 / BO1”，或“总决赛 / 第 1 轮 / BO5”。总决赛第 2 轮表示启用后的重赛。'};
    return {kinds:third?[['upper','主赛程'],['third','季军赛']]:[['upper','主赛程']],example:'例如第 1 轮 BO1、第 3 轮 BO3。轮次从首轮开始计数；如果总共 3 轮，第 3 轮就是决赛。'+(third?'季军赛单独选择“季军赛 / 第 1 轮”。':'')};
}
function roundBoHelp(format,third){const info=roundBoInfo(format,third);return `<strong>按轮次设置 BO，不用填写英文或 JSON</strong><p>这是可选设置。点击“添加轮次规则”，填写第几轮，再选择 BO1、BO3 或 BO5。</p><p>${esc(info.example)}</p><p>没有添加规则的轮次，继续使用上方“本阶段 BO 规则”。${groupFormats.includes(format)?'小组赛已经由当前赛制确定，无需再填写 group；这里的轮次是组内赛程轮次。':''}</p><p>只想让整个阶段统一使用 BO 时，不添加任何规则即可。要取消某一轮的覆盖，点击该行的“删除”。</p><p>优先级：单场／批量手动调整 ＞ 此处的轮次规则 ＞ 阶段规则。保存阶段设置后，新生成的赛程会按这些规则设置。</p>`;}
function rankingHelp(format){const example=format==='swiss'?'wins,buchholz,head_to_head_wins,head_to_head_round,seed':'points,wins,head_to_head_wins,head_to_head_round,game_difference,seed';return `<strong>如何填写排名比较顺序</strong><p>以下英文名称用<strong>英文半角逗号</strong>分隔，从左到右依次比较；前一项相同，才比较下一项。</p><pre>${esc(example)}</pre><dl><dt>points</dt><dd>积分，高者优先。</dd><dt>wins</dt><dd>比赛胜场，多者优先。</dd><dt>head_to_head_wins</dt><dd>仍同分队伍相互交手的小循环比赛胜场，多者优先。旧名称 head_to_head 自动兼容为此项。</dd><dt>head_to_head_round</dt><dd>仍同分队伍相互交手的净胜回合，累计每张地图填入的 A 小分减 B 小分；高者优先。只统计已结束的比赛。</dd><dt>game_difference</dt><dd>净胜地图数（胜图数减负图数），不是地图内回合净胜分。</dd><dt>buchholz</dt><dd>已交手对手的胜场总和，高者优先。</dd><dt>seed</dt><dd>种子序号，小者优先。</dd></dl><p>例如三张地图的小分是 13:8、8:13、13:11，则净胜回合为 +5−5+2＝+2；整场净胜地图则是 2−1＝+1。</p><p>“仅记录胜者”没有地图小分，head_to_head_round 与净胜地图项均无法区分队伍，将继续比较后一项。已有赛程也能单独保存排名顺序；已锁定后续阶段时需先解除该锁定。</p>`;}
function boFields(s,c){return `<div class="form-grid"><div data-base-bo>${select('本阶段 BO','base_bo',{1:'BO1',3:'BO3',5:'BO5'},c.base_bo)}</div><div data-bo-policy-control>${select('BO 设置方式','bo_policy',{uniform:'本阶段统一 BO',decisive:'普通比赛与晋级／淘汰战分别设置'},c.bo_policy)}</div><div data-decisive-bo>${select('晋级／淘汰战 BO','decisive_bo',{1:'BO1',3:'BO3',5:'BO5'},c.decisive_bo)}</div></div><div class="toolbar">${button('使用预设：普通 BO1，晋级／淘汰战 BO3','bo-preset',`data-id="${s.id}"`,'small secondary')}</div><p class="fine" data-bo-description></p>`;}
function readRoundRows(form){return $$('.round-bo-row',form).map(row=>({branch:$('[data-round-branch]',row).value,round:$('[data-round-number]',row).value,bo:$('[data-round-value]',row).value}));}
function roundRuleRow(rule,kinds){return `<div class="round-bo-row">${kinds.length>1?`<label>赛程区域<select data-round-branch>${kinds.map(([key,label])=>option(key,label,rule.branch)).join('')}</select></label>`:`<input type="hidden" data-round-branch value="${esc(kinds[0][0])}">`}<label>第几轮<input type="number" data-round-number min="1" max="256" value="${esc(rule.round)}" required ${rule.branch==='third'?'readonly':''}></label><label>BO<select data-round-value>${[1,3,5].map(bo=>option(bo,'BO'+bo,rule.bo)).join('')}</select></label>${button('删除','round-remove','','small danger')}</div>`;}
function syncRoundEditor(form,format,third){
    const info=roundBoInfo(format,third);const key=format+':'+third;
    if(form._roundEditorKey===key)return;
    if(!form._roundDrafts){const stage=data.stages.find(s=>+s.id===+form.dataset.id);form._roundDrafts=Object.entries(stage?.configuration.round_bo||{}).map(([k,bo])=>({branch:k.split(':')[0],round:k.split(':')[1],bo}));}
    else {const visible=form._roundVisibleKinds||[];form._roundDrafts=[...form._roundDrafts.filter(r=>!visible.includes(r.branch)),...readRoundRows(form)];}
    const kinds=info.kinds.map(([k])=>k);const rows=form._roundDrafts.filter(r=>kinds.includes(r.branch));
    $('[data-round-list]',form).innerHTML=rows.map(r=>roundRuleRow(r,info.kinds)).join('');
    $('[data-round-empty]',form).hidden=rows.length>0;
    form._roundVisibleKinds=kinds;form._roundEditorKey=key;
}
function collectRoundBo(form){const result={};for(const row of readRoundRows(form)){const round=+row.round;if(!Number.isInteger(round)||round<1||round>256)throw new Error('轮次请填写 1 至 256 的整数。');const key=row.branch+':'+round;if(Object.hasOwn(result,key))throw new Error('同一赛程区域的第 '+round+' 轮只能设置一条 BO 规则。');result[key]=+row.bo;}return result;}
function syncStageForm(form){
    const format=form.elements.format_type.value;const rr=groupFormats.includes(format);const single=format==='single';const hasNext=form.dataset.hasNext==='1';
    $$('[data-stage-formats],[data-stage-next]',form).forEach(block=>{const applies=!block.dataset.stageFormats||block.dataset.stageFormats.split(' ').includes(format);const visible=applies&&(!block.hasAttribute('data-stage-next')||hasNext);block.hidden=!visible;$$('input,select,textarea',block).forEach(control=>{control.disabled=!visible;});});
    const policy=form.elements.bo_policy;const uniform=rr||single;const selected=uniform?'uniform':policy.value;
    policy.innerHTML=option('uniform','本阶段统一 BO',selected)+(uniform?'':option('decisive','普通比赛与晋级／淘汰战分别设置',selected));
    $('[data-bo-policy-control]',form).hidden=single;policy.disabled=single;
    const decisive=$('[data-decisive-bo]',form);decisive.hidden=uniform||policy.value!=='decisive';$$('select',decisive).forEach(control=>{control.disabled=decisive.hidden;});
    $('[data-action="bo-preset"]',form).hidden=uniform;
    $('[data-bo-description]',form).textContent=single?'统一设置本阶段 BO，或在下方直接添加各轮 BO 规则。':rr?'本阶段所有小组使用统一 BO；下方只需填写轮次和 BO，无需填写 group。':format==='swiss'?'按本轮开始前的战绩识别：任一方再赢一场即可达到晋级胜场，或再输一场即达到淘汰负场时，使用晋级／淘汰战 BO；其余使用常规 BO。':'胜者组普通比赛使用常规 BO；败者组与总决赛可以使用单独的 BO。';
    const third=single&&form.elements.third.checked;
    $('[data-round-bo-help]',form).innerHTML=roundBoHelp(format,third);$('[data-ranking-help]',form).innerHTML=rankingHelp(format);
    syncRoundEditor(form,format,third);
}
function renderFormats(){
    $('#content').innerHTML=heading('赛制设置','选择赛制后，仅显示本阶段适用的配置项。')+data.stages.map(s=>{
        const c=s.configuration;
        const hasNext=+s.stage_order<+data.tournament.stage_count;
        return `<section class="card"><div class="section-heading"><h2>${esc(s.name)}</h2>${pill(s.status)}</div><form data-form="stage_save" data-id="${s.id}" data-has-next="${hasNext?'1':'0'}"><div class="form-grid">${field('阶段名称','name',s.name,'text','required maxlength="100"')}${select('赛制','format_type',formats,s.format_type)}${+s.stage_order===2?'<div class="notice">第二阶段种子由第一阶段赛果自动确定：小组名次、瑞士轮战绩或淘汰赛最终名次优先。可在锁定晋级前手动调整分组与首轮对阵。</div>':select('队伍排序方式','seed_mode',{seed:'按种子排序',random:'随机排序'},c.seed_mode)}${stageOptionBlock(groupFormats,field('小组数量','groups',c.groups,'number','min="1" max="32"')+field('胜利积分','win_points',c.win_points,'number','min="0" max="100"')+field('失败积分','loss_points',c.loss_points,'number','min="0" max="100"'))}${stageOptionBlock(['swiss'],field('最大轮数','rounds',c.rounds,'number','min="1" max="30"')+field('晋级所需胜场','qualify_wins',c.qualify_wins,'number','min="1" max="30"')+field('淘汰所需负场','eliminate_losses',c.eliminate_losses,'number','min="1" max="30"'))}${+s.stage_order===2?'':stageOptionBlock(['single','double'],select('首轮对阵方式','initial_pairing',{standard:'标准种子对阵（1 对末位）',adjacent:'相邻种子对阵（1 对 2）'},c.initial_pairing))}<div class="span-two" data-stage-next>${field('晋级下一阶段队伍数','advance_count',c.advance_count,'number','min="2" max="128"')}</div>${stageOptionBlock(groupFormats,field('每组晋级名额（0=按总排名选取）','per_group',c.per_group,'number','min="0" max="128"'),true)}<div class="span-two" data-stage-formats="single">${check('设置季军赛','third',c.third)}</div><div class="span-two" data-stage-formats="double">${check('启用总决赛重赛（Bracket Reset）','reset',c.reset)}</div></div><h3>本阶段 BO 规则</h3>${boFields(s,c)}<div class="field-with-help" data-stage-formats="${rankingFormats.join(' ')}"><label>排名比较顺序<input name="ranking" value="${esc(c.ranking.join(','))}" required aria-describedby="ranking-help-${s.id}"></label><aside class="field-explanation" id="ranking-help-${s.id}" data-ranking-help></aside></div><div class="field-with-help"><div class="round-bo-editor" aria-describedby="round-bo-help-${s.id}"><h3>各轮 BO 覆盖（可选）</h3><p class="fine" data-round-empty>尚未单独指定轮次，使用上方阶段 BO 规则。</p><div data-round-list></div>${button('＋ 添加轮次规则','round-add','','small secondary')}</div><aside class="field-explanation" id="round-bo-help-${s.id}" data-round-bo-help></aside></div><input type="hidden" name="id" value="${s.id}">${formEnd('保存阶段设置')}</form></section>`;
    }).join('');
    $$('form[data-form="stage_save"]').forEach(syncStageForm);
}
function renderSchedule(){$('#content').innerHTML=heading('分组与赛程','自动编排真实对阵，并保存上下游晋级关系。')+data.stages.map(s=>{const ms=data.matches.filter(m=>+m.stage_id===+s.id);return `<section class="card"><div class="section-heading"><h2>${esc(s.name)}</h2>${pill(s.status)}</div><div class="stage-meta"><span class="pill">${formats[s.format_type]}</span><span class="pill">${ms.length} 场比赛</span><span class="pill">${+s.stage_order===2?'按第一阶段赛果排种子':s.configuration.seed_mode==='random'?'随机种子':'按种子排序'}</span></div><div class="toolbar">${+s.stage_order===1&&!ms.length?button('生成赛程','generate',`data-id="${s.id}"`,'primary'):''}${ms.length?button('分组设置 / 首轮对阵','draw-edit',`data-id="${s.id}"`,'secondary'):''}${s.format_type==='swiss'&&ms.length?`${button('生成下一轮','swiss-next',`data-id="${s.id}"`,'primary')}${button('撤回最后一轮','swiss-undo',`data-id="${s.id}"`,'danger')}`:''}${ms.length||+s.locked?button('重置本阶段','stage-reset',`data-id="${s.id}"`,'danger'):''}${+s.stage_order===2&&!+s.locked?'<span class="fine">第一阶段结束后，在“排名与晋级”中确认参赛名单。</span>':''}</div>${data.groups.filter(g=>+g.stage_id===+s.id).map(g=>`<div class="list-row"><strong>${esc(g.name)}</strong><span class="fine">${data.group_members.filter(m=>+m.group_id===+g.id).map(m=>esc(teamName(m.team_id))).join(' / ')}</span></div>`).join('')}${ms.length?`<div class="tabs"><button class="active" disabled>对阵预览</button></div>${['single','double'].includes(s.format_type)?bracket(s):matchesTable(ms,false)}`:''}</section>`;}).join('');drawBrackets();}
function matchFilters(){const fs=[['stage','全部阶段',data.stages.map(s=>[s.id,s.name])],['round','全部轮次',[...new Set(data.matches.map(m=>+m.round_number))].sort((a,b)=>a-b).map(r=>[r,'第 '+r+' 轮'])],['group','全部小组',data.groups.map(g=>[g.id,g.name])],['status','全部状态',Object.entries(statuses).filter(([k])=>['scheduled','live','completed','postponed','cancelled'].includes(k))],['team','全部队伍',data.teams.map(t=>[t.id,t.name])]];return `<div class="filters">${fs.map(([key,all,choices])=>`<select aria-label="${all}" data-filter="${key}">${option('',all,filters[key])}${choices.map(([v,l])=>option(v,l,filters[key])).join('')}</select>`).join('')}<select data-filter="sort" aria-label="排序方式">${option('number','按比赛编号',filters.sort)}${option('time','按时间升序',filters.sort)}${option('time_desc','按时间降序',filters.sort)}</select></div>`;}
function filteredMatches(){let ms=data.matches.filter(m=>+m.is_active);for(const [key,column] of [['stage','stage_id'],['round','round_number'],['group','group_id']])if(filters[key])ms=ms.filter(m=>String(m[column])===filters[key]);if(filters.status)ms=ms.filter(m=>m.effective_status===filters.status);if(filters.team)ms=ms.filter(m=>String(m.team_a_id)===filters.team||String(m.team_b_id)===filters.team);if(filters.sort.startsWith('time'))ms.sort((a,b)=>{if(!a.scheduled_at)return 1;if(!b.scheduled_at)return -1;return a.scheduled_at.localeCompare(b.scheduled_at)*(filters.sort==='time_desc'?-1:1);});return ms;}
function matchesTable(ms,editable=false){
    if(!ms.length)return empty('暂无符合条件的比赛');
    return `<div class="table-scroll"><table><thead><tr>${editable?'<th><input type="checkbox" data-select-all aria-label="选择当前列表全部比赛"></th>':''}<th>比赛 / 阶段</th><th>时间</th><th>双方对阵</th><th>规则</th><th>胜者</th><th>状态</th>${editable?'<th>操作</th>':''}</tr></thead><tbody>${ms.map(m=>{const score=matchScore(m);return `<tr class="${editable?'':'match-detail-row'} ${m.effective_status==='live'?'match-live-row':''}" ${editable?'':`data-action="match-detail" data-id="${m.id}"`}>
    ${editable?`<td><input type="checkbox" data-match-select="${m.id}" ${selected.has(+m.id)?'checked':''} aria-label="选择比赛 ${m.match_number}" ${+m.is_bye||!+m.is_active||resolved(m)?'disabled':''}></td>`:''}<td><button class="match-detail-link mono" type="button" data-action="match-detail" data-id="${m.id}" aria-label="查看比赛 ${m.match_number} 详情">#${String(m.match_number).padStart(3,'0')} ↗</button><div class="fine">${esc(stageName(m.stage_id))} · 第 ${m.round_number} 轮${m.group_id?' · '+esc(data.groups.find(g=>+g.id===+m.group_id)?.name):''}</div></td><td>${esc(localTime(m.scheduled_at))}</td><td><div class="match-versus"><span>${teamHtml(m.team_a_id,m.winner_id&&+m.winner_id===+m.team_a_id)}</span><span class="score">${+m.is_bye?'轮空':score?score.map(esc).join(' : '):'VS'}</span><span>${teamHtml(m.team_b_id,m.winner_id&&+m.winner_id===+m.team_b_id)}</span></div>${+m.forfeit_team_id?`<div class="fine forfeit-label">${esc(teamName(m.forfeit_team_id))} 判负 · 判罚比分 ${+m.forfeit_team_id===+m.team_a_id?'0:13':'13:0'}</div>`:+m.best_of===1&&m.maps?.length?`<div class="fine">${esc(m.maps[0].map_name)}</div>`:''}<div class="fine match-detail-hint">点击查看地图、比分与比赛说明</div></td><td>BO${m.best_of}${m.bo_reason?`<div class="fine">${esc(m.bo_reason)}</div>`:''}${data.stages.find(s=>+s.id===+m.stage_id)?.format_type==='double_round_robin'?'<div class="fine">A 主场 / B 客场</div>':''}</td><td>${m.winner_id?teamHtml(m.winner_id,true):'—'}</td><td>${pill(m.effective_status)}</td>${editable?`<td>${button(+m.is_bye||!+m.is_active?'编辑说明':'编辑比赛','match-edit',`data-id="${m.id}"`,'small secondary')}</td>`:''}</tr>`;}).join('')}</tbody></table></div>`;
}
function standings(s,withSchedule=false){const rows=data.standings.filter(r=>+r.stage_id===+s.id),groups=data.groups.filter(g=>+g.stage_id===+s.id);if(!rows.length)return empty('赛程生成后显示排名');return (groups.length?groups:[{id:null,name:s.format_type==='swiss'?'瑞士轮战绩':'阶段排名'}]).map(g=>`<section class="card"><div class="section-heading"><h2>${esc(g.name)}</h2><span>${s.format_type==='swiss'?s.configuration.qualify_wins+' 胜晋级 · '+s.configuration.eliminate_losses+' 负淘汰':'排名自动计算'}</span></div><div class="table-scroll"><table><thead><tr><th>排名</th><th>队伍</th><th>胜</th><th>负</th><th>积分</th><th>净胜局</th><th>对手胜场和</th><th>状态</th></tr></thead><tbody>${rows.filter(r=>String(r.group_id)===String(g.id)).map(r=>{const q=s.format_type==='swiss'?+r.wins>=s.configuration.qualify_wins:s.configuration.per_group>0?+r.rank_position<=s.configuration.per_group:+r.rank_position<=s.configuration.advance_count;const eliminated=s.format_type==='swiss'&&+r.losses>=s.configuration.eliminate_losses;return `<tr><td><span class="${q?'winner-text':''}">${String(r.rank_position).padStart(2,'0')}</span></td><td>${teamHtml(r.team_id)}</td><td>${r.wins}</td><td>${r.losses}</td><td><strong>${r.points}</strong></td><td>${+r.game_difference>0?'+':''}${r.game_difference}</td><td>${r.buchholz}</td><td>${q?`<span class="status qualified">${s.format_type==='swiss'?'已晋级':'晋级区'}</span>`:eliminated?'<span class="status eliminated">已淘汰</span>':'—'}</td></tr>`;}).join('')}</tbody></table></div>${withSchedule?`<div class="group-schedule"><h3>${esc(g.name)} · 赛程</h3>${matchesTable(data.matches.filter(m=>+m.stage_id===+s.id&&String(m.group_id)===String(g.id)))}</div>`:''}</section>`).join('');}
function bracket(s){let ms=data.matches.filter(m=>+m.stage_id===+s.id);if(!ms.length&&s.preview_nodes){const ids=Object.fromEntries(s.preview_nodes.map((n,i)=>[n.key,'p-'+s.id+'-'+i]));const edges=[];ms=s.preview_nodes.map((n,i)=>{for(const slot of ['a','b'])if(typeof n[slot]==='string'){const [type,key]=n[slot].split(':');edges.push({source_match_id:ids[key],target_match_id:ids[n.key],source_result_type:type,target_slot:slot});}return {id:ids[n.key],stage_id:s.id,bracket:n.bracket,round_number:n.round,node_key:n.key,team_a_id:null,team_b_id:null,best_of:s.configuration.round_bo[n.bracket+':'+n.round]||s.configuration.base_bo,status:'scheduled',effective_status:n.key==='RESET'?'inactive':'scheduled',is_bye:0,is_active:n.key==='RESET'?0:1,score_a:null,score_b:null,match_number:'TBD',scheduled_at:null};});previewEdges.set(String(s.id),edges);}if(!ms.length)return empty('赛程尚未生成');const branches=[['upper','胜者组 / UPPER BRACKET'],['lower','败者组 / LOWER BRACKET'],['final','总决赛 / GRAND FINAL'],['third','季军赛 / THIRD PLACE']];return `<div class="bracket-scroll"><div class="bracket-board" data-bracket="${s.id}"><svg class="bracket-lines" aria-hidden="true"></svg>${s.preview_nodes?'<div class="notice">第一阶段尚未确认晋级名单。第二阶段席位显示 TBD。</div>':''}${branches.filter(([b])=>ms.some(m=>m.bracket===b)).map(([branch,title])=>{const branchMatches=ms.filter(m=>m.bracket===branch),rs=[...new Set(branchMatches.map(m=>+m.round_number))].sort((a,b)=>a-b);return `<section class="bracket-section"><div class="bracket-label">${title}</div><div class="bracket-track">${rs.map(r=>`<div class="bracket-round"><div class="round-title"><span>${branch==='final'?(r===2?'重赛（按赛果启用）':'总决赛'):'第 '+r+' 轮'}</span><span>BO${branchMatches.find(m=>+m.round_number===r).best_of}</span></div><div class="round-stack">${branchMatches.filter(m=>+m.round_number===r).map(m=>`<article class="bracket-match ${m.effective_status==='live'?'live':''}" data-node="${m.id}" ${String(m.id).startsWith('p-')?'':`data-action="match-detail" data-id="${m.id}" role="button" tabindex="0" aria-label="查看比赛 ${m.match_number} 详情"`}>${m.effective_status==='live'?`<div class="bracket-live-badge">${liveBadge()}</div>`:''}<div class="bracket-team ${m.winner_id&&+m.winner_id===+m.team_a_id?'winner':''}">${teamHtml(m.team_a_id)}<strong>${matchScore(m)?esc(matchScore(m)[0]):m.winner_id&&+m.winner_id===+m.team_a_id?'✓':''}</strong></div><div class="bracket-team ${m.winner_id&&+m.winner_id===+m.team_b_id?'winner':''}">${teamHtml(m.team_b_id)}<strong>${matchScore(m)?esc(matchScore(m)[1]):m.winner_id&&+m.winner_id===+m.team_b_id?'✓':''}</strong></div><div class="bracket-foot"><span>#${String(m.match_number).padStart(3,'0')} · BO${m.best_of} · ${+m.is_bye?'轮空':statuses[m.effective_status]}${+m.forfeit_team_id?' · 判负':''}</span><span>${esc(localTime(m.scheduled_at))}</span></div></article>`).join('')}</div></div>`).join('')}</div></section>`;}).join('')}</div></div>`;}
function drawBrackets(){requestAnimationFrame(()=>{$$('[data-bracket]').forEach(board=>{$$('.bracket-track',board).forEach(track=>{const stacks=$$('.round-stack',track),height=Math.max(...stacks.map(x=>x.children.length))*155;stacks.forEach(x=>x.style.height=height+'px');});const rect=board.getBoundingClientRect(),svg=$('svg',board);svg.setAttribute('width',board.scrollWidth);svg.setAttribute('height',board.scrollHeight);svg.replaceChildren();[...data.dependencies,...[...previewEdges.values()].flat()].forEach(d=>{const source=$(`[data-node="${d.source_match_id}"]`,board),target=$(`[data-node="${d.target_match_id}"]`,board);if(!source||!target)return;const a=source.getBoundingClientRect(),b=target.getBoundingClientRect(),slot=$$('.bracket-team',target)[d.target_slot==='a'?0:1]?.getBoundingClientRect();const x1=a.right-rect.left,y1=a.top+a.height/2-rect.top,x2=b.left-rect.left,y2=(slot?slot.top+slot.height/2:b.top+b.height/2)-rect.top;const path=document.createElementNS('http://www.w3.org/2000/svg','path');if(x2>x1)path.setAttribute('d',`M${x1} ${y1} H${(x1+x2)/2} V${y2} H${x2}`);else path.setAttribute('d',`M${x1} ${y1} h20 V${y2-15} H${x2-14} V${y2} H${x2}`);if(d.source_result_type==='loser')path.classList.add('loser');svg.append(path);});});});}
function renderPublic(){const t=data.tournament,s=currentStage();if(!selectedStage&&s)selectedStage=String(s.id);const anyLive=data.matches.some(m=>m.effective_status==='live');const underway=anyLive||data.matches.some(m=>resolved(m)&&!+m.is_bye);const complete=data.stages.length&&data.stages.every(s=>s.status==='completed');const tabs=[['overview','赛事概况'],['groups','小组与排名'],['swiss','瑞士轮'],['bracket','淘汰赛对阵'],['matches','全部比赛'],['teams','参赛队伍'],['attachments','赛事附件']].filter(([key])=>key!=='groups'||data.stages.some(s=>['round_robin','double_round_robin'].includes(s.format_type))).filter(([key])=>key!=='swiss'||data.stages.some(s=>s.format_type==='swiss')).filter(([key])=>key!=='bracket'||data.stages.some(s=>['single','double'].includes(s.format_type)));
$('#content').innerHTML=`<section class="card event-hero">${t.logo_path?`<img class="event-logo" src="${esc(t.logo_path)}" alt="赛事 Logo">`:''}<div><div class="eyebrow">TOURNAMENT / LIVE EVENT</div><h1>${esc(t.name)}</h1>${t.description?`<p class="event-description">${esc(t.description)}</p>`:''}<div class="event-meta"><span>${data.teams.length} 支参赛队伍</span><span>${data.stages.length} 个赛事阶段</span><span>${esc(t.timezone)}</span><span>${s?esc(s.name):'阶段待定'}</span></div></div><div class="hero-status">${pill(t.status==='archived'?'archived':complete?'completed':anyLive?'live':underway?'running':'scheduled')}</div></section><nav class="tabs">${tabs.map(([k,l])=>`<button data-tab="${k}" class="${tab===k?'active':''}">${l}</button>`).join('')}</nav><div class="public-tools">${tab!=='matches'&&tab!=='teams'&&tab!=='attachments'?`<select id="stage-select" aria-label="选择赛事阶段">${data.stages.map(st=>option(st.id,st.name+' · '+formats[st.format_type],s?.id)).join('')}</select>`:'<span></span>'}<span class="refresh-note"><span class="live-dot"></span><span id="refresh-status">每 20 秒自动同步 · ${esc(localTime(data.server_time.slice(0,19).replace('T',' ')))}</span></span></div><div id="public-body"></div>`;
const out=$('#public-body');if(tab==='teams'){out.innerHTML=`<div class="team-grid">${data.teams.map(t=>`<div class="team-tile"><span class="seed">#${String(t.seed).padStart(2,'0')}</span>${teamHtml(t.id)}</div>`).join('')}</div>`;if(!data.teams.length)out.innerHTML=empty('队伍名单尚未公布');}
else if(tab==='matches')out.innerHTML=`<section class="card">${matchFilters()}${matchesTable(filteredMatches())}</section>`;
else if(tab==='attachments')out.innerHTML='<section class="card"><h2>赛事附件</h2>'+attachmentList(data.public_attachments||[])+'</section>';
else if(!s)out.innerHTML=empty('赛事阶段尚未设置');
else if(tab==='bracket')out.innerHTML=['single','double'].includes(s.format_type)?`<section class="card">${bracket(s)}</section>`:empty('此阶段使用 '+formats[s.format_type]+'，请切换阶段查看淘汰赛');
else if(tab==='groups')out.innerHTML=['round_robin','double_round_robin'].includes(s.format_type)?standings(s,true):empty('此阶段不是循环赛，请切换阶段');
else if(tab==='swiss'){const ms=data.matches.filter(m=>+m.stage_id===+s.id);const rounds=[...new Set(ms.map(m=>+m.round_number))].sort((a,b)=>a-b);if(!swissRound||!rounds.includes(+swissRound))swissRound=String(rounds.at(-1)||1);out.innerHTML=s.format_type==='swiss'?`<div class="swiss-rounds">${rounds.map(r=>`<button data-swiss-round="${r}" class="${+swissRound===r?'active':''}">第 ${r} 轮</button>`).join('')}</div><section class="card">${matchesTable(ms.filter(m=>+m.round_number===+swissRound))}</section>${standings(s)}`:empty('此阶段不是瑞士轮，请切换阶段');}
else{const ms=data.matches.filter(m=>+m.stage_id===+s.id&&+m.is_active&&!+m.is_bye),finished=ms.filter(resolved).length;const finals=ms.filter(m=>s.format_type==='double'?m.bracket==='final':m.bracket==='upper'),last=finals.at(-1);out.innerHTML=`${s.status==='completed'&&['single','double'].includes(s.format_type)&&last?.winner_id?`<div class="banner-champion">冠军 / CHAMPION <strong>${esc(teamName(last.winner_id))}</strong></div>`:''}<div class="stats">${stat('阶段赛制',`<span class="score">${formats[s.format_type]}</span>`,esc(s.name),true)}${stat('本阶段比赛',ms.length,'不含轮空')}${stat('已完成比赛',finished,'赛果确认后自动晋级')}${stat('进行中',ms.filter(m=>m.effective_status==='live').length,'赛程实时同步')}</div><section class="card"><div class="section-heading"><h2>正在进行 / 即将开始</h2><span>当前阶段</span></div>${matchesTable(ms.filter(m=>m.status!=='completed'&&m.status!=='cancelled').sort((a,b)=>((a.scheduled_at||'z').localeCompare(b.scheduled_at||'z'))).slice(0,8))}</section>${['single','double'].includes(s.format_type)?`<section class="card"><h2>晋级之路</h2>${bracket(s)}</section>`:standings(s)}`;}
drawBrackets();}
function renderAdvance(){$('#content').innerHTML=heading('排名与晋级','第一阶段结束后，预览名单并确认锁定第二阶段。',data.stages.length===2?button('预览晋级名单','preview','','primary'):'')+`${data.stages.length<2?'<div class="notice">当前为单阶段赛事。可在赛事设置中启用双阶段。</div>':'<div class="notice">锁定后将根据第二阶段的种子和对阵规则生成赛程。需要更正第一阶段时，请先重置第二阶段。</div>'}`+data.stages.map(s=>`<div class="section-heading"><h2>${esc(s.name)} · ${formats[s.format_type]}</h2>${pill(s.status)}</div>${standings(s)}`).join('');}
async function renderLogs(append=false){$('#content').innerHTML=heading(mode==='global'?'全局操作日志':'操作日志','历史记录只允许追加，无法在后台修改或删除。',mode==='global'?button('校验哈希链','verify-logs'):'')+'<section class="card"><div id="log-list">正在读取日志…</div></section>';try{const r=await request('/logs'+(append&&logCursor?'?before='+logCursor:''));logs=append?[...logs,...r.logs]:r.logs;logCursor=logs.at(-1)?.id||'';$('#log-list').innerHTML=logs.length?logs.map(l=>`<article class="log-item"><div class="log-header"><div><strong>${esc(actions[l.action]||l.action)}</strong> <span class="pill">#${l.id}</span> <span class="fine">赛事 ${l.tournament_id||'全局'} · ${esc(l.actor_type)}</span></div><span class="fine">${esc(localTime(l.created_at))} · ${esc(l.ip_address)}</span></div><details><summary>查看修改前后数据与哈希</summary><div class="log-columns"><div><p class="fine">修改前</p><pre>${esc(pretty(l.old_data))}</pre></div><div><p class="fine">修改后</p><pre>${esc(pretty(l.new_data))}</pre></div></div><p class="mono">Previous: ${esc(l.previous_hash)}<br>Entry: ${esc(l.entry_hash)}</p></details></article>`).join('')+(r.logs.length===50?`<div class="modal-actions">${button('加载更早记录','more-logs')}</div>`:''):empty('暂无操作记录');}catch(e){$('#log-list').innerHTML=empty(e.message);}}
function pretty(v){try{return JSON.stringify(typeof v==='string'?JSON.parse(v):v,null,2);}catch{return String(v);}}
function captureGameDrafts(form){form._gameDrafts||=[];$$('[data-game-row]',form).forEach(row=>{const index=+row.dataset.gameRow;form._gameDrafts[index]={map:$('[data-game-map]',row).value,a:$('[data-game-a]',row)?.value??'',b:$('[data-game-b]',row)?.value??''};});}
function attachmentList(files, manage=false){
    if(!files.length)return empty('暂无附件');
    return files.map(file=>{
        const size=+file.size_bytes<1024*1024?Math.max(1,Math.round(file.size_bytes/1024))+' KB':(file.size_bytes/(1024*1024)).toFixed(1)+' MB';
        const id=encodeURIComponent(file.id);
        const visibility=+file.is_public?'private':'public';
        const changeLabel=+file.is_public?'改为仅管理员可见':'改为公开';
        const change=manage&&file.match_id===null
            ?button(changeLabel,'attachment-visibility','data-id="'+id+'" data-visibility="'+visibility+'"','small secondary')
            :'';
        const remove=manage?button('删除','attachment-delete','data-id="'+id+'"','small danger'):'';
        return '<div class="list-row attachment-row"><div><strong>'+esc(file.original_name)+'</strong>'
            +'<div class="fine">'+size+' · '+esc(localTime(file.created_at))+(manage?(+file.is_public?' · 公开':' · 仅管理员可见'):'')+'</div></div>'
            +'<div class="toolbar"><a class="button small secondary" href="'+esc(api)+'/attachment_download?id='+id+'" download>下载</a>'
            +change+remove+'</div></div>';
    }).join('');
}

function mountAttachmentPanel(container, matchId=null){
    const format=matchId===null?'PDF 或 XLSX':'XLSX';
    const acceptedTypes=matchId===null?'.pdf,.xlsx':'.xlsx';
    const panel=document.createElement('section');
    panel.className=matchId===null?'card attachment-panel':'attachment-panel match-attachment-panel';
    panel.dataset.attachmentPanel=matchId??'';
    const visibility=matchId===null
        ?select('可见范围','visibility',{private:'仅管理员可见',public:'向所有访客公开'},'private')
        :'<p class="fine">比赛附件仅有权管理本赛事的管理员可下载。</p>';
    panel.innerHTML='<h2>'+(matchId===null?'赛事附件':'比赛附件')+'</h2>'
        +'<div data-attachment-list aria-live="polite"><p class="fine">正在读取附件…</p></div>'
        +'<form data-form="attachment_upload"><label>选择 '+format+' 文件'
        +'<input type="file" name="attachment" accept="'+acceptedTypes+'" multiple required>'
        +'<span class="hint">可多选，每份最大 10 MB。</span></label>'
        +visibility+'<p data-attachment-status class="fine" role="status"></p>'
        +'<button class="button" type="submit">上传附件</button></form>';
    container.append(panel);
    void refreshAttachmentPanel(panel);
}

async function refreshAttachmentPanel(panel){
    const list=$('[data-attachment-list]',panel);
    const matchId=panel.dataset.attachmentPanel;
    try{
        const result=await request('/attachments?admin=1'+(matchId?'&match_id='+encodeURIComponent(matchId):''));
        list.innerHTML=attachmentList(result.attachments,true);
    }catch(error){list.innerHTML=empty(error.message);}
}

async function submitAttachments(form){
    const panel=form.closest('[data-attachment-panel]');
    if(panel.dataset.busy)return;
    const input=form.elements.attachment;
    const files=[...input.files];
    const submit=$('[type="submit"]',form);
    const status=$('[data-attachment-status]',panel);
    const visibility=form.elements.visibility?.value??'private';
    const matchId=panel.dataset.attachmentPanel;
    const errors=[];
    let uploaded=0;
    panel.dataset.busy='1';
    submit.disabled=true;
    input.disabled=true;
    if(form.elements.visibility)form.elements.visibility.disabled=true;
    try{
        for(const [index,file] of files.entries()){
            status.textContent='正在上传 '+(index+1)+' / '+files.length+'…';
            if(file.size===0||file.size>10*1024*1024){
                errors.push(file.name+'：文件必须非空且不超过 10 MB。');
                continue;
            }
            const payload=new FormData();
            payload.set('attachment',file);
            payload.set('visibility',visibility);
            if(matchId)payload.set('match_id',matchId);
            try{
                await request('/attachment_upload',payload,true);
                uploaded++;
            }catch(error){errors.push(file.name+'：'+error.message);}
        }
        input.value='';
        status.textContent=['已上传 '+uploaded+' / '+files.length+' 份附件。',...errors].join('\n');
        await refreshAttachmentPanel(panel);
    }finally{
        delete panel.dataset.busy;
        submit.disabled=false;
        input.disabled=false;
        if(form.elements.visibility)form.elements.visibility.disabled=false;
    }
}

async function changeAttachment(button){
    const panel=button.closest('[data-attachment-panel]');
    if(!panel||panel.dataset.busy)return;
    const remove=button.dataset.action==='attachment-delete';
    if(remove&&!window.confirm('确定删除这份附件？'))return;
    const status=$('[data-attachment-status]',panel);
    panel.dataset.busy='1';
    button.disabled=true;
    try{
        const payload={id:button.dataset.id,visibility:button.dataset.visibility};
        const result=await request(remove?'/attachment_delete':'/attachment_visibility',payload);
        status.textContent=result.message;
        await refreshAttachmentPanel(panel);
    }finally{
        delete panel.dataset.busy;
        button.disabled=false;
    }
}

function renderGameRows(form){
    captureGameDrafts(form);const bo=+form.elements.best_of.value;const pool=data.tournament.map_pool||[];const detailed=form.dataset.detailed==='1'&&!form.elements.forfeit_team_id.value;
    $('[data-game-list]',form).innerHTML=Array.from({length:bo},(_,i)=>{const g=form._gameDrafts[i]||{map:'',a:'',b:''};const names=[...pool];if(g.map&&!names.includes(g.map))names.push(g.map);return `<section class="game-map-block" data-game-row="${i}"><h3>第 ${i+1} 局</h3><label>地图<select data-game-map aria-label="第 ${i+1} 局地图">${option('',pool.length?'未设置地图':'请先在赛事设置填写地图池',g.map)}${names.map(name=>option(name,name+(!pool.includes(name)?'（历史记录）':''),g.map)).join('')}</select></label>${detailed?`<div class="form-grid"><label>队伍 A 小分<input type="number" data-game-a aria-label="第 ${i+1} 局 A 小分" min="0" max="10000" value="${esc(g.a)}"></label><label>队伍 B 小分<input type="number" data-game-b aria-label="第 ${i+1} 局 B 小分" min="0" max="10000" value="${esc(g.b)}"></label></div>`:''}</section>`;}).join('');
    const trimmed=form._gameDrafts.slice(bo).some(g=>g&&(g.map||g.a!==''||g.b!==''));const note=$('[data-bo-trim-note]',form);note.hidden=!trimmed;note.textContent='当前 BO'+bo+' 只保存前 '+bo+' 局。后续局已暂时隐藏，恢复原 BO 可继续编辑；保存时不会提交隐藏的比分或地图。';
}
function matchModal(id){
    const notes=data.matches.find(m=>+m.id===+id);if(!notes)return;
    if(+notes.is_bye||!+notes.is_active){openModal('编辑比赛说明 #'+notes.match_number,`<form data-form="match_edit" data-notes-only="1"><input type="hidden" name="id" value="${notes.id}"><input type="hidden" name="version" value="${notes.version}"><p class="fine">轮空或尚未启用的比赛由系统自动推进，可以单独填写比赛说明。</p><label>比赛说明<textarea name="description" rows="6" maxlength="10000">${esc(notes.description||'')}</textarea></label>${formEnd('保存比赛说明')}</form>`);mountAttachmentPanel($('#modal-body'),id);return;}
    const m=data.matches.find(m=>+m.id===+id),stage=data.stages.find(s=>+s.id===+m.stage_id),entries=data.stage_teams.filter(t=>+t.stage_id===+m.stage_id);const choices={'':'TBD',...Object.fromEntries(entries.map(t=>[t.team_id,teamName(t.team_id)]))};const detailed=data.tournament.score_mode==='detailed';
    openModal('编辑比赛 #'+String(m.match_number).padStart(3,'0'),`<p class="fine">${esc(stage.name)} · 第 ${m.round_number} 轮 · 时间以 ${esc(data.tournament.timezone)} 输入</p><form data-form="match_edit" data-detailed="${detailed?'1':'0'}"><input type="hidden" name="id" value="${m.id}"><input type="hidden" name="version" value="${m.version}"><div class="form-grid">${select('队伍 A','team_a_id',choices,m.team_a_id||'')}${select('队伍 B','team_b_id',choices,m.team_b_id||'')}${select('BO 规则','best_of',{1:'BO1',3:'BO3',5:'BO5'},m.best_of)}${select('比赛状态','status',Object.fromEntries(Object.entries(statuses).filter(([k])=>['scheduled','live','completed','postponed','cancelled'].includes(k))),m.status)}${field('比赛时间（留空为 TBA）','scheduled_at',localTime(m.scheduled_at,true),'datetime-local')}${!detailed?select('获胜队伍（清空可撤回结果）','winner_id',{'':'尚未确认',...Object.fromEntries([m.team_a_id,m.team_b_id].filter(Boolean).map(id=>[id,teamName(id)]))},m.winner_id||''):''}</div><label>比赛说明<textarea name="description" rows="5" maxlength="10000" placeholder="可填写赛前通知、直播地址等；HTTP/HTTPS 链接会在公开比赛详情中变为可点击链接。">${esc(m.description||'')}</textarea></label><div class="form-grid">${select('判负队伍','forfeit_team_id',{'':'不判负',...Object.fromEntries([m.team_a_id,m.team_b_id].filter(Boolean).map(id=>[id,teamName(id)+' 判负']))},m.forfeit_team_id||'')}</div><div class="notice" data-forfeit-note hidden>被判负队伍按 0:13 负于对方，状态自动设为已取消。判罚仍计入排名与晋级；BO3 / BO5 整场分别判为 2:0 / 3:0，获胜所需的每一局均记为 13:0。撤回判负时需重新录入正常比赛结果。</div><h3>${detailed?'逐局地图与比分':'逐局地图'}</h3><p class="fine">BO1 显示 1 局，BO3 显示 3 局，BO5 显示 5 局。地图可先选择，比分可稍后填写。${detailed?'仅填写已经结束的小局；双方小分同时留空表示尚未进行，保存后自动计算整场胜者。':'本模式不填写地图内小分。'} 修改地图池请到赛事设置。</p><div class="notice" data-bo-trim-note hidden></div><div data-game-list></div><div class="form-error" role="alert"></div><div class="modal-actions">${button('取消','close')}<button class="button" type="submit">保存比赛</button></div></form>`);
    const form=$('form[data-form="match_edit"]',$('#modal'));form._gameDrafts=Array.from({length:5},(_,i)=>{const game=m.games.find(g=>+g.game_number===i+1);const planned=(m.maps||[]).find(g=>+g.game_number===i+1);return {map:planned?.map_name||game?.map_name||'',a:game?.team_a_score??'',b:game?.team_b_score??''};});syncForfeit(form,false);renderGameRows(form);
    mountAttachmentPanel($('#modal-body'),id);
}

function syncForfeit(form,changed=true){
    const verdict=form.elements.forfeit_team_id;const previous=verdict.value;
    verdict.innerHTML=option('','不判负',previous)+[form.elements.team_a_id.value,form.elements.team_b_id.value].filter(Boolean).map(id=>option(id,teamName(id)+' 判负',previous)).join('');
    const active=!!verdict.value;const wasActive=form.dataset.forfeiting==='1';
    if(changed){captureGameDrafts(form);if(wasActive&&!active){form._gameDrafts=form._gameDrafts.map(g=>({...g,a:'',b:''}));if(form.elements.winner_id)form.elements.winner_id.value='';form.elements.status.value='scheduled';}}
    form.dataset.forfeiting=active?'1':'0';$('[data-forfeit-note]',form).hidden=!active;
    form.elements.status.disabled=active;if(active)form.elements.status.value='cancelled';if(form.elements.winner_id)form.elements.winner_id.disabled=active;
    // Rows are rebuilt after clearing a verdict, so old penalty scores cannot return.
    if(changed){$('[data-game-list]',form).innerHTML='';renderGameRows(form);}
}
function eliminationOrder(size){let order=[1,2];while(order.length<size){const sum=order.length*2+1;order=order.flatMap(seed=>[seed,sum-seed]);}return order;}
function initialPairs(s,teams){
    const ids=teams.map(t=>+t.team_id),knockout=['single','double'].includes(s.format_type);let size=knockout?2:ids.length+(ids.length%2);if(knockout)while(size<ids.length)size*=2;
    if(knockout&&s.configuration.initial_pairing==='adjacent'){const actual=ids.length-size/2;return Array.from({length:size/2},(_,i)=>i<actual?{a:ids[i*2],b:ids[i*2+1]}:{a:ids[actual*2+i-actual],b:null});}
    const slots=Array.from({length:size},(_,i)=>ids[i]||null),order=knockout&&s.configuration.initial_pairing!=='adjacent'?eliminationOrder(size):Array.from({length:size},(_,i)=>i+1);
    return Array.from({length:size/2},(_,i)=>({a:slots[order[i*2]-1],b:slots[order[i*2+1]-1]}));
}
function drawFields(s,teams,defaults={},manual=false){
    const rr=['round_robin','double_round_robin'].includes(s.format_type);let body='';
    if(rr){
        const assignment=new Map();const names=Array.from({length:s.configuration.groups},(_,i)=>'第 '+(i+1)+' 组');
        (defaults.groups||[]).forEach((group,i)=>{names[i]=group.name;for(const id of group.teams)assignment.set(+id,i);});
        body=`<div class="group-assignment">${teams.map((t,i)=>{const row=Math.floor(i/s.configuration.groups),column=i%s.configuration.groups,automatic=row%2?s.configuration.groups-1-column:column;return `<label><span>${esc(t.name)}${t.source_label?`<small class="fine"> · ${esc(t.source_label)}</small>`:''}</span><select name="group_${t.team_id}">${names.map((name,g)=>option(g,name,assignment.get(+t.team_id)??automatic)).join('')}</select></label>`;}).join('')}</div>`;
    } else {
        const pairs=defaults.pairings||initialPairs(s,teams);const choices={'':'轮空',...Object.fromEntries(teams.map(t=>[t.team_id,t.name+(t.source_label?' · '+t.source_label:'')]))};
        body=`<div class="draw-pair-list">${pairs.map((pair,i)=>`<section class="draw-pair" data-draw-pair><h3>首轮第 ${i+1} 场</h3><div class="form-grid">${select('队伍 A','pair_a_'+i,choices,pair.a||'')}${select('队伍 B','pair_b_'+i,choices,pair.b||'')}</div></section>`).join('')}</div><p class="fine">每队必须出现一次。参赛队数不足满编时可保留轮空；同一场不能双方都轮空。${s.format_type==='swiss'?'这里只设置首轮，后续轮次仍按战绩自动配对。':''}</p>`;
    }
    return `<div class="draw-editor">${manual?'':check(rr?'手动调整队伍所在小组':'手动调整首轮对阵','manual_draw',false)}<div data-draw-fields ${manual?'':'hidden'}>${body}</div></div>`;
}
function readDraw(form,s,teams){
    if(['round_robin','double_round_robin'].includes(s.format_type)){
        const oldGroups=data.groups.filter(g=>+g.stage_id===+s.id);
        return {groups:Array.from({length:s.configuration.groups},(_,i)=>({name:oldGroups[i]?.name||'第 '+(i+1)+' 组',teams:teams.filter(t=>+form.elements['group_'+t.team_id].value===i).map(t=>+t.team_id)}))};
    }
    return {pairings:$$('[data-draw-pair]',form).map((row,i)=>({a:+form.elements['pair_a_'+i].value||null,b:+form.elements['pair_b_'+i].value||null}))};
}
function generateModal(id){
    const s=data.stages.find(s=>+s.id===+id),teams=data.teams.map(t=>({team_id:+t.id,name:t.name}));
    openModal('生成 '+s.name+' 赛程',`<form data-form="generate"><input type="hidden" name="stage_id" value="${id}"><div class="notice">${esc(formats[s.format_type])} · ${teams.length} 支队伍。未勾选手动调整时按阶段设置自动编排；勾选后以所填分组或首轮对阵为准。</div>${drawFields(s,teams)}${formEnd('确认生成赛程')}</form>`);
}
function drawModal(id){
    const s=data.stages.find(s=>+s.id===+id),teams=data.stage_teams.filter(t=>+t.stage_id===+id).sort((a,b)=>+a.seed-+b.seed).map(t=>({team_id:+t.team_id,name:teamName(t.team_id)}));
    const groups=data.groups.filter(g=>+g.stage_id===+id).map(g=>({name:g.name,teams:data.group_members.filter(m=>+m.group_id===+g.id).map(m=>+m.team_id)}));
    const pairings=data.matches.filter(m=>+m.stage_id===+id&&+m.round_number===1&&(['single','double'].includes(s.format_type)?m.bracket==='upper':s.format_type==='swiss')).map(m=>({a:m.team_a_id,b:m.team_b_id}));
    openModal('分组设置 · '+s.name,`<form data-form="stage_draw"><input type="hidden" name="stage_id" value="${id}"><div class="notice">保存后重排本阶段赛程。已有开赛、比分、判负或后续瑞士轮时不能重排；第二阶段已锁定时不能重排第一阶段。相同对阵的时间、地图与说明会保留，新对阵需重新填写。比赛编号可能变化，阶段种子顺序保留。</div>${drawFields(s,teams,{groups,pairings},true)}${formEnd('保存分组与首轮对阵')}</form>`);
}
function advanceDraw(){const s=data.stages.find(s=>+s.stage_order===2);return `<div class="notice">种子按第一阶段赛果排列。循环赛按小组名次分档；瑞士轮按胜场多、负场少排序（例如 3-0、3-1、3-2）。淘汰赛／瑞士轮首轮强弱配对，尽量避开原同组对手；新循环赛按种子分档均衡分组。</div><h3>自动分组 / 首轮对阵预览</h3>${drawPreview(s,preview)}${drawFields(s,preview.teams,preview)}`;}
function drawPreview(s,draw){
    if(draw.groups.length)return draw.groups.map(group=>`<p class="fine"><strong>${esc(group.name)}</strong>：${group.teams.map(id=>esc(teamName(id))).join(' / ')}</p>`).join('');
    return `<ol class="draw-preview">${draw.pairings.map(pair=>`<li>${esc(teamName(pair.a))} <span>vs</span> ${pair.b?esc(teamName(pair.b)):'轮空'}</li>`).join('')}</ol>`;
}
function batchModal(){const ids=[...selected];if(!ids.length){toast('请先在比赛列表中勾选需要修改的比赛。',true);return;}openModal('批量设置 '+ids.length+' 场比赛',`<form data-form="batch"><div class="notice">按比赛编号升序分配时间。可用阶段和轮次筛选后，勾选当前列表的全部比赛。</div>${field('第一场时间（留空清除时间）','scheduled_at','','datetime-local')}${field('每场间隔（分钟，0 为相同时间）','interval',0,'number','min="0" max="10080"')}${select('BO 规则','best_of',{'':'保留原规则',1:'BO1',3:'BO3',5:'BO5'},'')}${formEnd('应用到选中比赛')}</form>`);}
document.addEventListener('click',async e=>{const nav=e.target.closest('[data-page]');if(nav){page=nav.dataset.page;selected.clear();mode==='global'?renderGlobal():renderAdmin();return;}const tb=e.target.closest('[data-tab]');if(tb){tab=tb.dataset.tab;renderPublic();return;}const sr=e.target.closest('[data-swiss-round]');if(sr){swissRound=sr.dataset.swissRound;renderPublic();return;}const b=e.target.closest('[data-action]');if(!b)return;const id=+b.dataset.id;try{switch(b.dataset.action){
case 'close':closeModal();break;
case 'attachment-delete':
case 'attachment-visibility':await changeAttachment(b);break;
case 'map-pool-reset':{const f=b.closest('form');$('[data-map-pool]',f).value=defaultMapPools.cs2.join('\n');break;}
case 'round-add':{const f=b.closest('form');const info=roundBoInfo(f.elements.format_type.value,f.elements.third.checked&&f.elements.format_type.value==='single');const branch=info.kinds[0][0];const used=new Set(readRoundRows(f).filter(r=>r.branch===branch).map(r=>+r.round));let round=1;while(used.has(round)&&round<256)round++;$('[data-round-list]',f).insertAdjacentHTML('beforeend',roundRuleRow({branch,round,bo:f.elements.base_bo.value},info.kinds));$('[data-round-empty]',f).hidden=true;break;}
case 'round-remove':{const f=b.closest('form');b.closest('.round-bo-row').remove();$('[data-round-empty]',f).hidden=$$('.round-bo-row',f).length>0;break;}

case 'bo-preset':{const f=$(`form[data-form="stage_save"][data-id="${id}"]`);f.elements.base_bo.value='1';f.elements.bo_policy.value='decisive';f.elements.decisive_bo.value='3';syncStageForm(f);break;}
case 'logout':{const old=api;try{if(body.dataset.global==='1')api='/api/admin';const r=await request('/logout',{});location.href=r.redirect;}finally{api=old;}break;}
case 'create':openModal('创建赛事',`<form data-form="create">${settingsFields({},true)}${formEnd('创建赛事')}</form>`);break;
case 'team-edit':{const t=team(id);openModal('编辑队伍',`<form data-form="team_edit"><input type="hidden" name="id" value="${id}">${field('队伍名称','name',t.name,'text','required maxlength="100"')}${field('种子序号','seed',t.seed,'number','min="1" max="128" required')}${formEnd()}</form>`);break;}
case 'team-delete':await confirmAction('删除队伍','确认删除 '+teamName(id)+'？此操作会记录日志。',()=>mutate('team_delete',{id}));break;
case 'logo-delete':await confirmAction('删除赛事 Logo','确认移除赛事及所有队伍使用的统一 Logo？',()=>mutate('logo_delete'));break;
case 'generate':generateModal(id);break;
case 'draw-edit':drawModal(id);break;
case 'stage-reset':await confirmAction('重置阶段','将删除本阶段的比赛、比分、比赛附件、分组和晋级名单。请确认已备份所需数据。',()=>mutate('stage_reset',{stage_id:id}));break;
case 'swiss-next':openModal('生成下一轮瑞士轮',`<form data-form="swiss_next"><input type="hidden" name="stage_id" value="${id}"><p class="muted">本轮比赛必须全部结束。系统按战绩配对，优先避免重复交手。</p>${check('允许重复交手（仅在配对冲突时选择）','allow_rematches',false)}${formEnd('生成下一轮')}</form>`);break;
case 'swiss-undo':await confirmAction('撤回最后一轮','仅能撤回尚未开始的最后一轮，该轮比赛附件也将删除。',()=>mutate('swiss_undo',{stage_id:id}));break;
case 'match-detail':matchDetail(id);break;
case 'match-edit':matchModal(id);break;
case 'batch':batchModal();break;
case 'preview':{preview=await request('/advance-preview');openModal('确认第二阶段晋级名单',`<p class="fine">以下顺序将作为第二阶段的初始种子。确认后名单锁定并生成赛程。</p><ol class="advance-list">${preview.teams.map((t,i)=>`<li><span>#${i+1}</span><strong>${esc(t.name)}</strong><span>${esc(t.source_label)} · ${t.wins} 胜 ${t.losses} 负 · ${t.points} 分</span></li>`).join('')}</ol><form data-form="advance">${advanceDraw()}${formEnd('锁定名单并生成赛程')}</form>`);break;}
case 'password':{const t=data.tournaments.find(t=>+t.id===id);openModal('重置 '+t.name+' 管理密码',`<form data-form="password" data-slug="${esc(t.slug)}">${field('新赛事管理密码','password','','password','required minlength="12" maxlength="200" autocomplete="new-password"')}<p class="fine">保存后，原赛事管理会话立即失效。</p>${formEnd('确认重置密码')}</form>`);break;}
case 'tournament-status':{const t=data.tournaments.find(t=>+t.id===id);openModal('修改赛事状态',`<form data-form="status" data-slug="${esc(t.slug)}">${select('赛事状态','status',{active:'开放中',disabled:'停用（公开页面和赛事管理员无法访问）',archived:'归档（保留公开页面）'},t.status)}${formEnd('确认修改')}</form>`);break;}
case 'more-logs':await renderLogs(true);break;
case 'verify-logs':{const r=await request('/verify-logs');toast('日志哈希链校验通过，共 '+r.count+' 条记录。');break;}
}}catch(err){toast(err.message,true);}});
document.addEventListener('change',e=>{const el=e.target;const form=el.closest('form');if(el.name==='manual_draw'&&form)$('[data-draw-fields]',form).hidden=!el.checked;if(el.name==='game'&&form){$('[data-game-summary]',form).textContent=gameSummary(el.value);switchMapPool(form,el.value);}if(el.matches('[data-round-branch]')){const row=el.closest('.round-bo-row');const n=$('[data-round-number]',row);n.readOnly=el.value==='third';if(n.readOnly)n.value='1';}if(form?.matches('[data-form="match_edit"]')&&el.name==='best_of')renderGameRows(form);if(form?.matches('[data-form="match_edit"]')&&['forfeit_team_id','team_a_id','team_b_id'].includes(el.name))syncForfeit(form);if(form?.matches('[data-form="match_edit"]')&&['team_a_id','team_b_id'].includes(el.name)&&form.elements.winner_id){const winner=form.elements.winner_id;const selected=winner.value;winner.innerHTML=option('','尚未确认',selected)+[form.elements.team_a_id.value,form.elements.team_b_id.value].filter(Boolean).map(id=>option(id,teamName(id),selected)).join('');}if(form?.matches('[data-form="stage_save"]')&&['bo_policy','format_type','third'].includes(el.name))syncStageForm(form);if(el.matches('[data-filter]')){filters[el.dataset.filter]=el.value;selected.clear();mode==='public'?renderPublic():renderAdmin();}else if(el.id==='stage-select'){selectedStage=el.value;swissRound='';renderPublic();}else if(el.matches('[data-match-select]')){el.checked?selected.add(+el.dataset.matchSelect):selected.delete(+el.dataset.matchSelect);}else if(el.matches('[data-select-all]')){$$('[data-match-select]:not(:disabled)').forEach(c=>{c.checked=el.checked;el.checked?selected.add(+c.dataset.matchSelect):selected.delete(+c.dataset.matchSelect);});}});
document.addEventListener('input',e=>{if(e.target.matches('[data-slug-field]')){const f=e.target.closest('form');$('[data-slug-preview]',f).textContent=location.origin+'/tournaments/'+(e.target.value||'cs2-campus-2026');}});
document.addEventListener('submit',async e=>{const f=e.target;if(!f.matches('[data-form]'))return;e.preventDefault();if(f.dataset.form==='attachment_upload'){await submitAttachments(f);return;}const op=f.dataset.form,submit=$('[type="submit"]',f);submit.disabled=true;$('.form-error',f).textContent='';try{let d=Object.fromEntries(new FormData(f));
if(op==='settings'){d=readSettings(f);if(+d.stage_count<+data.tournament.stage_count){submit.disabled=false;await confirmAction('删除第二阶段','改为单阶段会删除第二阶段的全部比赛、比分、比赛附件和参赛名单。',()=>mutate('settings',d));return;}}
if(op==='create'){d=readSettings(f);const r=await request('/create',d);location.href='/tournaments/'+r.slug+'/admin';return;}
if(op==='stage_save'){
    const roundBO=collectRoundBo(f);
    const original=data.stages.find(stage=>+stage.id===+d.id)?.configuration||{};
    const configuration={...original,...d,bo_policy:d.format_type==='single'?'uniform':d.bo_policy,third:d.format_type==='single'&&f.elements.third.checked,reset:d.format_type==='double'?f.elements.reset.checked:(original.reset??true),ranking:d.ranking===undefined?(original.ranking||['points','wins','head_to_head_wins','head_to_head_round','game_difference','buchholz','seed']):d.ranking.split(',').map(value=>value.trim()).filter(Boolean),round_bo:roundBO};
    d={id:d.id,name:d.name,format_type:d.format_type,configuration};
}
if(op==='match_edit'&&f.dataset.notesOnly!=='1'){
    d.games=[];d.maps=[];
    for(const row of $$('[data-game-row]',f)){
        const i=+row.dataset.gameRow;const map=$('[data-game-map]',row).value;if(map)d.maps.push({game_number:i+1,map_name:map});
        if(f.dataset.detailed!=='1'||f.elements.forfeit_team_id.value)continue;
        const a=$('[data-game-a]',row).value,b=$('[data-game-b]',row).value;
        if(a===''&&b==='')continue;if(a===''||b==='')throw new Error('每局双方小分需要同时填写。');if(i!==d.games.length)throw new Error('请按小局顺序连续填写比分。');d.games.push({a:+a,b:+b});
    }
}
if(op==='generate'&&f.elements.manual_draw?.checked){const s=data.stages.find(s=>+s.id===+d.stage_id);Object.assign(d,readDraw(f,s,data.teams.map(t=>({team_id:t.id}))));}
if(op==='stage_draw'){const s=data.stages.find(s=>+s.id===+d.stage_id);Object.assign(d,readDraw(f,s,data.stage_teams.filter(t=>+t.stage_id===+s.id)));}
if(op==='swiss_next')d.allow_rematches=f.elements.allow_rematches.checked;
if(op==='batch')d.ids=[...selected];if(op==='advance'){d.fingerprint=preview.fingerprint;if(f.elements.manual_draw?.checked){const s=data.stages.find(s=>+s.stage_order===2);Object.assign(d,readDraw(f,s,preview.teams));}}
if(op==='logo'){const logo=f.elements.logo.files[0];if(logo&&logo.size>10*1024*1024)throw new Error('Logo 不能超过 10 MB。');const r=await request('/logo',new FormData(f),true);toast(r.message);await load();}
else if(op==='password'||op==='status'){const old=api;try{api='/api/tournaments/'+f.dataset.slug;const r=await request('/'+op,d);toast(r.message);}finally{api=old;}await load();}
else await mutate(op,d);
selected.clear();closeModal();
}catch(err){$('.form-error',f).textContent=err.message;submit.disabled=false;}});
document.addEventListener('keydown',e=>{const node=e.target.closest('.bracket-match[data-action="match-detail"]');if(node&&(e.key==='Enter'||e.key===' ')){e.preventDefault();matchDetail(+node.dataset.id);}});
window.addEventListener('resize',drawBrackets);
load().catch(e=>{$('#content').innerHTML=`<section class="card">${empty(e.message)}</section>`;toast(e.message,true);});
if(mode==='public')setInterval(async()=>{if(document.hidden)return;try{await load();}catch(err){const status=$('#refresh-status');if(status)status.textContent='同步失败，将自动重试';}},20000);
})();
