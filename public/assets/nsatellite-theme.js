/* Shared NSatellite theme preference: local to the browser, no account secrets. */
(function(){
 'use strict';
 const KEY='nsatellite-theme-v1';
 const OPTIONS=[['nos','NOS moderne'],['mediasat','CanalSatellite (inspiré)'],['tps','TPS (inspiré)']];
 const VALID=new Set(OPTIONS.map(option=>option[0]));
 function read(){
  try{const value=localStorage.getItem(KEY);return VALID.has(value)?value:'nos';}
  catch{return 'nos';}
 }
 function apply(value,save){
  if(!VALID.has(value))return;
  document.documentElement.dataset.theme=value;
  document.body.dataset.theme=value;
  // /player.html already exposes #theme and uses data-theme; keep it synchronized.
  document.querySelectorAll('select[data-ns-theme],select#theme').forEach(select=>{if(select.value!==value)select.value=value;});
  if(save){try{localStorage.setItem(KEY,value);}catch{/* Private browsing / storage blocked. */}}
 }
 function init(){
  const header=document.querySelector('body header');
  let select=document.querySelector('select#theme');
  if(!select && header){
   const wrapper=document.createElement('div');wrapper.className='ns-theme-picker';
   const label=document.createElement('label');
   const id='ns-theme-select';label.htmlFor=id;label.textContent='Thème';
   select=document.createElement('select');select.id=id;select.dataset.nsTheme='true';
   select.setAttribute('aria-label','Thème visuel NSatellite');
   for(const [value,text] of OPTIONS)select.add(new Option(text,value));
   wrapper.append(label,select);header.append(wrapper);
  }
  if(select){
   select.addEventListener('change',()=>apply(select.value,true));
   select.classList.add('ns-theme-control');
  }
  apply(read(),false);
  window.addEventListener('storage',event=>{if(event.key===KEY)apply(read(),false);});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
})();
