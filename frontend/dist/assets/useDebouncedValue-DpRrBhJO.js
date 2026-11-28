import{k as o}from"./index-CcIu0e7f.js";function n(e,t=300){const[u,r]=o.useState(e);return o.useEffect(()=>{const s=setTimeout(()=>r(e),t);return()=>clearTimeout(s)},[e,t]),u}export{n as u};
