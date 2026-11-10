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
  if(!response.ok){
   const error=new Error(payload.error||'Erreur HTTP '+response.status);
   error.status=response.status;
   throw error;
  }
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
   // Only a genuine 401 means the cookie session has expired or was revoked.
   // Timeouts, reverse-proxy errors and 5xx responses must not masquerade as logout.
   let response;
   try{response=await request(BASE+'session');}
   catch{throw Error('Serveur de connexion inaccessible. Réessaie dans quelques instants.');}
   if(response.status===401)return false;
   const data=await response.json().catch(()=>null);
   if(!response.ok)throw Error(data?.error||'Vérification indisponible (HTTP '+response.status+')');
   if(!data||typeof data.authenticated!=='boolean')throw Error('Réponse de session invalide');
   return data.authenticated;
  },
  async logout(){
   await json(BASE+'logout',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
   if(channel)channel.postMessage('logout');
   return true;
  }
 });
})();
