'use strict';
const {createHash}=require('node:crypto');
const {requestJson}=require('./api');
const config=require('./config');
const {h}=require('./helpers');
const settings={site_name:'PlayMoz Lite Canais',site_description:'Jogos, canais e programação ao vivo.',accent:'#2563eb',default_timezone:'Africa/Maputo',analytics_enabled:false,default_embeds:[]};
async function api_get(path,query={}) {return await requestJson(config.CHANNELS_API_BASE.replace(/\/+$/,'')+'/'+path.replace(/^\/+/,'')+(Object.keys(query).length?'?'+new URLSearchParams(query):''))||{success:false,data:[]};}
function api_data(r,fallback=[]){return r?.data??r?.results??r?.sports??r?.events??(Array.isArray(r)?r:fallback);}
function normalize_embeds(list){if(!Array.isArray(list))return [];return list.map((e,i)=>typeof e==='string'?{embed_url:e,provider:'Servidor '+(i+1),quality:'HD'}:{embed_url:e?.embed_url??e?.url??e?.primary??'',provider:e?.provider??e?.name??'Servidor '+(i+1),quality:e?.quality??'HD'}).filter(e=>/^https?:\/\//i.test(e.embed_url));}
function status_from_timestamp(start,end){const now=Date.now()/1000;return !start||start>now?'upcoming':(!end||end>=now?'live':'finished');}
function team(e,side){const t=e.teams?.[side]||{};return {name:t.name||(side==='home'?'Casa':'Fora'),logo:t.logo||t.image||''};}
function timestamp(value){
 if(value==null||value==='')return 0;
 if(typeof value==='number'||/^\d{10,13}$/.test(String(value))) {
  const n=Number(value);return Number.isFinite(n)?Math.floor(n>1e11?n/1000:n):0;
 }
 const ms=Date.parse(String(value));return Number.isFinite(ms)?Math.floor(ms/1000):0;
}
function normalizeGame(raw){
 const data=raw.data||{},teams=raw.teams||data.teams||{};
 const start=timestamp(raw.start_timestamp??raw.time_start??raw.timestamp??raw.start??data.timer?.start);
 const end=timestamp(raw.end_timestamp??raw.time_end??data.timer?.end)||(start?start+7200:0);
 const id=String(raw.id||raw.slug||raw.uuid||'api-'+createHash('sha1').update(`${teams.home?.name||'home'}|${teams.away?.name||'away'}|${start}`).digest('hex').slice(0,20));
 const league=raw.league||data.league;
 return {...raw,id,slug:String(raw.slug||id),title:raw.title||`${teams.home?.name||'Casa'} x ${teams.away?.name||'Fora'}`,
  teams,competition:raw.competition||(typeof league==='object'?league?.name:league)||raw.category||'Futebol',
  poster:raw.poster||raw.image||'',start_timestamp:start,end_timestamp:end,
  status:status_from_timestamp(start,end),embeds:normalize_embeds(raw.embeds||raw.players||[])};
}
const remoteGamesUrl=process.env.PLAYMOZ_GAMES_JSON_URL||'https://apps.golplay.site/nexaa-painel/data-public.php';
let remoteCache={expires:0,games:[]};
async function remoteGames(){
 if(Date.now()<remoteCache.expires)return remoteCache.games;
 try{
  const response=await fetch(remoteGamesUrl,{headers:{Accept:'application/json'},signal:AbortSignal.timeout(8000)});
  if(!response.ok)throw new Error('HTTP '+response.status);
  const rawText=await response.text();
  if(/^\s*</.test(rawText))throw new Error('O alojamento devolveu HTML em vez de JSON no data-public.php (verifique endereço, publicação e bloqueios do alojamento)');
  const payload=JSON.parse(rawText);
  const items=Array.isArray(payload)?payload:(payload?.data?.events||payload?.data?.games||payload?.events||payload?.games||payload?.data);
  if(!Array.isArray(items))throw new Error('Formato JSON inválido: esperado array de jogos');
  remoteCache={expires:Date.now()+30000,games:items.slice(0,2000).filter(x=>x&&typeof x==='object').map(normalizeGame)};
 }catch(error){console.warn('Jogos do painel indisponíveis:',error.message);remoteCache.expires=Date.now()+15000;}
 return remoteCache.games.map(normalizeGame); // actualiza o estado ao vivo conforme a hora actual
}
// Combina a API antiga e o JSON do painel; alterações do painel têm prioridade.
async function originalGames(){
 const r=await api_get('/sports');
 const d=api_data(r);
 const items=Array.isArray(d)?d:(d?.events||d?.sports||d?.games||r?.events||r?.sports||[]);
 return Array.isArray(items)?items.filter(x=>x&&typeof x==='object').map(normalizeGame):[];
}
async function get_sports(status=''){
 const [oldGames,newGames]=await Promise.all([originalGames(),remoteGames()]);
 const games=new Map();
 const identity=g=>{
  const home=String(g.teams?.home?.name||'').trim().toLocaleLowerCase();
  const away=String(g.teams?.away?.name||'').trim().toLocaleLowerCase();
  const day=g.start_timestamp?new Date(g.start_timestamp*1000).toISOString().slice(0,10):'';
  return home&&away&&day?`${home}|${away}|${day}`:String(g.slug||g.id);
 };
 for(const g of [...oldGames,...newGames]){
  const key=identity(g);
  games.set(key,g);
 }
 return [...games.values()].map(normalizeGame).filter(g=>!status||g.status===status).sort((a,b)=>a.start_timestamp-b.start_timestamp);
}
function normalizeChannel(c){return {...c,id:String(c.id||c.slug||c.name),name:c.name||'Canal',logo_url:c.logo_url||c.image||c.logo||'',category:c.category||(c.categories?.includes('0099')?'Nacional':'TV'),embeds:normalize_embeds(c.embeds||c.players||(c.url?[c.url]:[])),epg:c.epg?.current?c.epg:{current:c.epg||{}}};}
async function get_channels(category=null){const r=await api_get('/channels');const d=api_data(r);const list=Array.isArray(d)?d:d.channels||r.channels||[];const map=new Map(list.map(c=>{const n=normalizeChannel(c);return[n.slug||n.id,n];}));return [...map.values()].filter(c=>!category||c.category===category||c.categories?.includes(category));}
async function find_channel(id){const local=(await get_channels()).find(c=>c.id===id||c.slug===id);if(local)return local;const d=api_data(await api_get('/channels/'+encodeURIComponent(id)),null);return d&&!Array.isArray(d)?normalizeChannel(d):null;}
async function find_sport(id){const local=(await get_sports()).find(g=>g.id===id||g.slug===id);if(local)return local;const d=api_data(await api_get('/sports/'+encodeURIComponent(id)),null);return d&&!Array.isArray(d)?normalizeGame(d):null;}
function detectTimezone(req){const cookie=(req.headers.cookie||'').match(/(?:^|;\s*)pm_timezone=([^;]+)/);let tz;try{tz=cookie&&decodeURIComponent(cookie[1]);if(tz&&Intl.DateTimeFormat('pt',{timeZone:tz}))return tz;}catch{}return {MZ:'Africa/Maputo',AO:'Africa/Luanda',BR:'America/Sao_Paulo'}[req.get('cf-ipcountry')]||settings.default_timezone;}
const local_time=(ts,tz='Africa/Maputo')=>ts?new Intl.DateTimeFormat('pt-PT',{timeZone:tz,hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date(ts*1000)):'--:--';
const paths={"home": "<path d=\"m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z\"/>", "live": "<circle cx=\"12\" cy=\"12\" r=\"8\"/><path d=\"m10 8 6 4-6 4z\" fill=\"currentColor\" stroke=\"none\"/>", "tv": "<rect x=\"3\" y=\"5\" width=\"18\" height=\"14\" rx=\"2\"/><path d=\"m8 2 4 3 4-3\"/>", "search": "<circle cx=\"11\" cy=\"11\" r=\"7\"/><path d=\"m20 20-4-4\"/>", "moon": "<path d=\"M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5 8.5 8.5 0 1 0 20.5 14.5z\"/>", "settings": "<path d=\"M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48 2.83-2.83\"/><circle cx=\"12\" cy=\"12\" r=\"3\"/>", "play": "<path d=\"m8 5 11 7-11 7z\" fill=\"currentColor\" stroke=\"none\"/>", "share": "<circle cx=\"18\" cy=\"5\" r=\"2\"/><circle cx=\"6\" cy=\"12\" r=\"2\"/><circle cx=\"18\" cy=\"19\" r=\"2\"/><path d=\"m8 11 8-5m-8 7 8 5\"/>", "clock": "<circle cx=\"12\" cy=\"12\" r=\"9\"/><path d=\"M12 7v5l3 2\"/>", "chevron": "<path d=\"m9 18 6-6-6-6\"/>", "menu": "<path d=\"M4 6h16M4 12h16M4 18h16\"/>", "close": "<path d=\"m6 6 12 12M18 6 6 18\"/>", "refresh": "<path d=\"M20 11a8 8 0 0 0-14.8-3L3 11m0 0V6m0 5h5M4 13a8 8 0 0 0 14.8 3L21 13m0 0v5m0-5h-5\"/>", "filter": "<path d=\"M4 5h16M7 12h10m-7 7h4\"/>", "trophy": "<path d=\"M8 4h8v5a4 4 0 0 1-8 0zM5 5H3v2a4 4 0 0 0 5 4m11-6h2v2a4 4 0 0 1-5 4M12 13v5m-4 3h8\"/>", "back": "<path d=\"m15 18-6-6 6-6\"/>", "calendar": "<rect x=\"3\" y=\"4\" width=\"18\" height=\"17\" rx=\"2\"/><path d=\"M16 2v4M8 2v4M3 9h18\"/>", "shield": "<path d=\"M12 3 20 6v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z\"/>"};
function icon(name,cls='icon'){return `<svg class="${h(cls)}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name]||''}</svg>`;}
module.exports={APP_VERSION:'1.7.0',app_settings:()=>settings,api_get,api_data,normalize_embeds,item_embeds:(type,id,embeds)=>normalize_embeds(embeds),status_from_timestamp,team,get_sports,get_channels,find_channel,find_sport,detectTimezone,local_time,icon,normalizeGame,normalizeChannel};
