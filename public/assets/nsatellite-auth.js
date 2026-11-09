/* NSatellite browser auth: the server owns a Secure HttpOnly session cookie.
 * No bearer tokens in JavaScript, localStorage, sessionStorage or URLs.
 */
(function(){
 'use strict';
 const BASE='/api/identity/browser/';
 // Notify other tabs of logout without sending any credential or personal data.
 const channel=typeof BroadcastChannel==='function'?new BroadcastChannel('nsatellite-browser-session'):null;
 if(channel)channel.onmessage=event=>{
  if(event.data==='logout')location.reload();
 };
 async function request(path,options={}){
  const method=(options.method||'GET').toUpperCase();
  const headers=new Headers(options.headers||{});
  if(!['GET','HEAD','OPTIONS'].includes(method)){
   headers.set('X-NSatellite-Request','1');
  }
  const response=await fetch(path,{
   ...options,method,headers,credentials:'same-origin',cache:'no-store',redirect:'error'
  });
  return response;
 }
 async function json(path,options={}){
  const response=await request(path,options);
  const payload=await response.json().catch(()=>({}));
  if(!response.ok)throw Error(payload.error||'Erreur HTTP '+response.status);
  return payload;
 }
 window.NSatelliteAuth=Object.freeze({
  request,
  json,
  async login(email,password){
   const data=await json(BASE+'login',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({email,password})
   });
   if(data.authenticated!==true)throw Error('La session navigateur n’a pas été créée');
   return true;
  },
  async session(){
   try{
    const data=await json(BASE+'session');
    return data.authenticated===true;
   }catch{return false;}
  },
  async logout(){
   await json(BASE+'logout',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
   if(channel)channel.postMessage('logout');
   return true;
  }
 });
})();
