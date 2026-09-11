import{c as o,b as i}from"./index-CavimD4W.js";/**
 * @license lucide-react v0.525.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const a=[["path",{d:"M4 12h16",key:"1lakjw"}],["path",{d:"M4 18h16",key:"19g7jn"}],["path",{d:"M4 6h16",key:"1o0s65"}]],s=o("menu",a),d="velin:notifications-changed",f={list:n=>i("/admin/notifications",{params:n}),counts:()=>i("/admin/notifications/counts"),markRead:n=>i(`/admin/notifications/${encodeURIComponent(n)}/read`,{method:"POST",body:JSON.stringify({})}).then(t=>t.notification),markAllRead:()=>i("/admin/notifications/read-all",{method:"POST",body:JSON.stringify({})}).then(n=>n.updated),clearAll:()=>i("/admin/notifications/clear",{method:"POST",body:JSON.stringify({})}).then(n=>n.cleared),setMuted:(n,t)=>i(`/admin/notification-preferences/${n}`,{method:"PATCH",body:JSON.stringify({muted:t})}).then(e=>e.preference.muted)};function h(){typeof window<"u"&&window.dispatchEvent(new Event(d))}export{s as M,d as N,h as d,f as n};
