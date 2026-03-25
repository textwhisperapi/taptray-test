<?php
include_once __DIR__ . '/includes/db_connect.php';
include_once __DIR__ . '/includes/functions.php';

sec_session_start();
if (!login_check($mysqli)) die("Not logged in");

$ownerId  = (int)$_SESSION['user_id'];
$memberId = 34;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Member Roles – Test</title>

<style>
/* component-only styling */
/* component-only styling */
.mp-box{
  padding:12px;
  border-radius:8px;
}

.mp-wrap{
  display:flex;
  gap:6px;
  flex-wrap:wrap;
}

.mp-role{
  padding:6px 10px;
  border-radius:999px;
  cursor:pointer;
  font-size:13px;
  display:inline-flex;
  align-items:center;
  gap:6px;

  /* SAFE fallbacks */
  background: var(--mp-pill-bg, var(--panel-button-bg, #e6e6e6));
  color: var(--mp-pill-text, var(--panel-button-text, #000));
}

.mp-role.active{
  background: var(--mp-pill-active-bg, var(--panel-accent, #4a90e2));
  color: var(--mp-pill-active-text, var(--panel-accent-text, #fff));
}

.mp-role.add{
  background: transparent;
  border: 1px dashed var(--panel-border, #999);
  color: inherit;
  opacity: 0.7;
}

.mp-del{
  opacity:.6;
  font-size:11px;
  cursor:pointer;
}

.mp-input{
  border:0;
  border-radius:999px;
  padding:6px 10px;
  font-size:13px;

  background: var(--panel-input-bg, #f0f0f0);
  color: var(--panel-input-text, #000);
}


</style>
</head>

<body>

<div class="mp-box">
  <div class="mp-wrap" id="roles"></div>
</div>

<script>
(() => {

const O = <?= $ownerId ?>, M = <?= $memberId ?>;
const el = document.getElementById("roles");
let roles=[], active="All";

const api = (u,o)=>fetch(u,o).then(r=>r.json());

async function load(){
  roles = (await api(`/getMemberRoles_json.php?owner_id=${O}`)).roles;
  active = (await api(`/getMemberRoleAssignment.php?owner_id=${O}&member_id=${M}`)).role || "All";
  draw();
}

async function save(){
  await api("/updateMemberRoles_json.php",{
    method:"POST",
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({roles})
  });
}

async function assign(role){
  active=role; draw();
  await api("/updateMemberRoleAssignment.php",{
    method:"POST",
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({member_id:M,role})
  });
}

function draw(){
  el.innerHTML="";
  roles.forEach(r=>{
    const d=document.createElement("div");
    d.className="mp-role"+(r===active?" active":"");
    d.textContent=r;
    d.onclick=()=>assign(r);

    if(r!=="All"){
      const x=document.createElement("span");
      x.className="mp-del";
      x.textContent="✕";
      x.onclick=e=>{e.stopPropagation(); remove(r)};
      d.appendChild(x);
    }

    d.ondblclick=()=>rename(r);
    el.appendChild(d);
  });

  const add=document.createElement("div");
  add.className="mp-role add";
  add.textContent="+";
  add.onclick=addRole;
  el.appendChild(add);
}

async function addRole(){
  const v=prompt("New role");
  if(!v||roles.includes(v))return;
  roles.push(v);
  await save();
  assign(v);
}

async function rename(old){
  const v=prompt("Rename role",old);
  if(!v||v===old||roles.includes(v))return;
  roles=roles.map(r=>r===old?v:r);
  if(active===old) active=v;
  await save();
  draw();
}

async function remove(r){
  if(!confirm(`Delete "${r}"?`))return;
  roles=roles.filter(x=>x!==r);
  if(active===r) active="All";
  await save();
  draw();
}

load();

})();
</script>

</body>
</html>
