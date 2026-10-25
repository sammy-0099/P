'use strict';
const express=require('express');
const path=require('node:path');
const {context,views}=require('./lib/render');
const {baseUrl,seoFor}=require('./lib/seo');
const {tmdb}=require('./lib/api');
const channels=require('./lib/channels');
const h=require('./lib/helpers');
const app=express();
app.disable('x-powered-by');
app.set('trust proxy',1);
app.set('query parser','simple');
app.use((req,res,next)=>{res.set('X-Content-Type-Options','nosniff');next();});
app.use(express.static(path.join(__dirname,'public'),{maxAge:'1d',index:false,dotfiles:'deny'}));
const wrap=fn=>(req,res,next)=>Promise.resolve(fn(req,res,next)).catch(next);
function queryFor(req,defaults={}) {const q={...defaults};for(const [key,value] of Object.entries(req.query))if(typeof value==='string')q[key]=value;for(const [key,value] of Object.entries(req.params))q[key]=value;if(q.p)q.p=Math.max(1,Math.min(500,Number.parseInt(q.p,10)||1));return q;}
function page(route,defaults={}) {return wrap(async(req,res)=>{
 const q=queryFor(req,defaults);q.route=route;
 const ctx=context(req,res,q),minimal=['login','signup'].includes(route);
 // Start independent home requests together; their promises are reused by the views.
 if(route==='home')await Promise.all(['/trending/all/day','/movie/popular','/movie/now_playing','/movie/top_rated','/tv/popular','/tv/top_rated','/trending/movie/week','/trending/tv/week','/person/popular'].map(p=>tmdb(p)));
 const [seo,pageHtml,navHtml,bottomHtml,whatsappHtml]=await Promise.all([seoFor(req,route,q),views['pages/'+route](ctx),minimal?'':views['pages/_nav'](ctx),minimal?'':views['pages/_bottom'](ctx),route==='home'?views['components/whatsapp-invite'](ctx):'']);
 if(route==='404')res.status(404);
 if(['movie','series','person','company'].includes(route)&&seo.noindex)res.status(404);
 res.type('html').send(await views.layout({...ctx,route,minimal,seo,metaTitle:seo.title,pageHtml,navHtml,bottomHtml,whatsappHtml}));
 });}
app.get('/',page('home'));
for(const [url,route] of Object.entries({'/catalogo':'catalog','/animes':'anime','/generos':'genres','/autores':'authors','/buscar':'search','/login':'login','/criar-conta':'signup','/conta':'account','/definicoes':'settings','/favoritos':'favorites','/planos':'plans','/sobre':'about','/privacidade':'privacy','/termos':'terms'}))app.get(url,page(route));
app.get('/filmes',page('catalog',{type:'movie'}));app.get('/series',page('catalog',{type:'tv'}));
app.get('/genero/filme/:id(\\d+)',page('genre',{type:'movie'}));app.get('/genero/serie/:id(\\d+)',page('genre',{type:'tv'}));
app.get('/genero/todos/:movie_id(\\d+)/:tv_id(\\d+)/:slug',page('genre',{type:'all'}));
app.get('/filme/:id(\\d+)',page('movie'));app.get('/serie/:id(\\d+)/temporada/:season(\\d+)',page('series'));app.get('/serie/:id(\\d+)',page('series'));app.get('/ator/:id(\\d+)',page('person'));app.get('/produtora/:id(\\d+)',page('company'));
app.get('/canais',(req,res,next)=>req.path.endsWith('/')?next():res.redirect(308,'/canais/'+(req.originalUrl.includes('?')?req.originalUrl.slice(req.originalUrl.indexOf('?')):'')));
app.get('/canais/',wrap(async(req,res)=>{
 const q=queryFor(req);if(q.game||q.channel)return res.redirect('/canais/player?type='+(q.channel?'channel':'sport')+'&id='+encodeURIComponent(q.channel||q.game));
 if(q.aba==='canais')q.tab='channels';if(q.categoria&&q.categoria!=='0')q.category=q.categoria;
 res.type('html').send(await views['canais/index'](context(req,res,q)));
}));
app.get('/canais/player',wrap(async(req,res)=>res.type('html').send(await views['canais/player'](context(req,res,queryFor(req))))));
app.get('/api/jogos',wrap(async(req,res)=>{res.set('Cache-Control','public, max-age=15').json(await channels.get_sports());}));
app.get('/canais/search',wrap(async(req,res)=>{
 const q=queryFor(req),ctx=context(req,res,q);const apiGet=ctx.api_get;
 // Fall back to the same API channel and game catalogues when search is unavailable.
 ctx.api_get=async(endpoint,params)=>{const data=channels.api_data(await apiGet(endpoint,params),{});if(data.channels?.length||data.events?.length)return {data};const needle=String(params.q||'').toLowerCase();const [cs,gs]=await Promise.all([channels.get_channels(),channels.get_sports()]);return {data:{channels:cs.filter(c=>c.name.toLowerCase().includes(needle)),events:gs.filter(g=>g.title.toLowerCase().includes(needle))}};};
 res.type('html').send(await views['canais/search'](ctx));
}));
// Compatibility redirects retain old bookmarks, without a PHP runtime or source files.
app.get(['/canais/index.php','/canais/player.php','/canais/search.php'],(req,res)=>{const target=req.path.endsWith('index.php')?'/canais/':req.path.replace(/\.php$/,'');res.redirect(308,target+(req.originalUrl.includes('?')?req.originalUrl.slice(req.originalUrl.indexOf('?')):''));});
app.get('/robots.txt',(req,res)=>res.type('text').send('User-agent: *\nAllow: /\nDisallow: /conta\nDisallow: /definicoes\nDisallow: /favoritos\nDisallow: /buscar\nSitemap: '+baseUrl(req)+'/sitemap.xml\n'));
app.get(['/sitemap.xml','/canais/sitemap.xml'],wrap(async(req,res)=>{
 const paths=new Set(['/','/filmes','/series','/animes','/catalogo','/generos','/sobre','/canais/']);
 const feeds={movie:['popular','top_rated','now_playing','upcoming'],tv:['popular','top_rated','on_the_air','airing_today']};
 await Promise.all(Object.entries(feeds).flatMap(([type,lists])=>lists.map(async feed=>{for(let p=1;p<=5;p++){const data=await tmdb(`/${type}/${feed}`,{page:p});if(!data?.results?.length)break;for(const m of data.results)if(m.id>0)paths.add('/'+(type==='movie'?'filme':'serie')+'/'+Number(m.id));}})));
 res.type('application/xml').set('Cache-Control','public, max-age=21600').send('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'+[...paths].map(p=>'<url><loc>'+h.h(baseUrl(req)+p)+'</loc></url>').join('')+'</urlset>');
}));
app.use(page('404'));
app.use((error,req,res,next)=>{console.error(error);if(res.headersSent)return next(error);res.status(500).type('html').send('<!doctype html><html lang="pt"><meta charset="utf-8"><title>PlayMoz Lite</title><link rel="stylesheet" href="/assets/css/app.css"><div class="container empty">Não foi possível carregar esta página. <a href="/">Voltar ao início</a></div></html>');});
if(require.main===module)app.listen(Number(process.env.PORT)||3000,()=>console.log('PlayMoz Lite: http://localhost:'+(process.env.PORT||3000)));
module.exports=app;
