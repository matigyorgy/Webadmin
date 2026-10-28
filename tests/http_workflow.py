"""Run only against a fresh, isolated installed test panel with the SSH fixture.
Required: GAMEDOCK_TEST_URL (loopback), GAMEDOCK_TEST_SSH_HOST/FINGERPRINT.
The fixture admin is owner@example.com / Web-test-password-123.
Never run against a production panel: this creates and removes test records.
"""
import html
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request

BASE=os.environ['GAMEDOCK_TEST_URL'].rstrip('/')
if urllib.parse.urlsplit(BASE).hostname not in ('127.0.0.1','localhost'):
    raise ValueError('Test URL must point to an isolated loopback fixture')
PASSWORD='Web-test-password-123'

class Browser:
    def __init__(self):
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(self,path,data=None):
        body=urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            response=self.opener.open(BASE+path,body,timeout=45)
            return response.status,response.read().decode()
        except urllib.error.HTTPError as error:
            return error.code,error.read().decode()
    def csrf(self,path='/'):
        status,body=self.request(path)
        assert status==200,(path,status)
        return re.search(r'name="csrf" value="([^"]+)"',body).group(1)
    def login(self,email,password=PASSWORD):
        status,body=self.request('/',{'csrf':self.csrf(),'action':'login','email':email,'password':password})
        assert status==200 and email in body and 'autocomplete="current-password"' not in body,'Login failed'
    def admin(self,section,data):
        return self.request('/admin.php?section='+section,dict(data,csrf=self.csrf('/admin.php?section='+section)))
    def action(self,server,action,command=''):
        return self.request('/?server='+str(server),{'csrf':self.csrf(),'server_id':server,'action':action,'command':command})

def record_id(body,text):
    for row in re.findall(r'<tr>(.*?)</tr>',body,re.S):
        if html.escape(text,quote=True) in row:
            match=re.search(r'(?:&|&amp;)edit=(\d+)',row)
            if match:return int(match.group(1))
    raise AssertionError('Record not listed: '+text)

admin=Browser();alice=Browser();bob=Browser();guest=Browser()
assert guest.request('/api.php?action=status&server_id=1')[0]==401
admin.login('owner@example.com')
for section in ('servers','nodes','users','audit','setup'):
    assert admin.request('/admin.php?section='+section)[0]==200
status,body=admin.request('/admin.php?section=users',{'csrf':'forged','action':'save','email':'forged@example.com','password':PASSWORD})
assert status==200 and 'érvénytelen űrlap' in body and 'forged@example.com' not in admin.request('/admin.php?section=users')[1]
for email in ('alice@example.com','bob@example.com'):
    status,body=admin.admin('users',{'action':'save','email':email,'password':PASSWORD})
    assert status==200 and 'A bejegyzést elmentettük' in body
    if email.startswith('alice'):alice_id=record_id(body,email)
    else:bob_id=record_id(body,email)
alice.login('alice@example.com');bob.login('bob@example.com')
assert alice.request('/admin.php')[0]==403
assert alice.request('/admin.php?section=users',{'action':'save','csrf':alice.csrf(),'email':'hacked@example.com','password':PASSWORD,'is_admin':'1'})[0]==403
node={'action':'save','name':'Test Linux <node>','ssh_host':os.environ['GAMEDOCK_TEST_SSH_HOST'],'ssh_port':'22','ssh_user':'gamedock','ssh_fingerprint':os.environ['GAMEDOCK_TEST_SSH_FINGERPRINT']}
status,body=admin.admin('nodes',node);node_id=record_id(body,node['name'])
assert '<node>' not in body and '&lt;node&gt;' in body
status,body=admin.admin('nodes',{'action':'test','id':node_id})
assert 'hitelesítés sikeres' in body,body[-2000:]
# Host-key mismatches must fail instead of silently trusting the new key.
admin.admin('nodes',dict(node,id=node_id,ssh_fingerprint='SHA256:'+'B'*43))
assert 'SSH-teszt sikertelen' in admin.admin('nodes',{'action':'test','id':node_id})[1]
admin.admin('nodes',dict(node,id=node_id))
server={'action':'save','name':'Survival <script>alert(1)</script>','game':'minecraft','unit_name':'minecraft.service','user_id':alice_id,'node_id':node_id}
status,body=admin.admin('servers',server);server_id=record_id(body,server['name'])
assert '<script>alert(1)</script>' not in body
status,body=admin.admin('servers',dict(server,name='Duplicate'))
assert 'már hozzá van adva' in body
second=dict(server,name='Second game',game='cs2',unit_name='cs2.service')
status,body=admin.admin('servers',second);second_id=record_id(body,second['name'])
assert alice.request('/')[1].count('Kezelés és konzol')==2
assert 'Survival' not in bob.request('/')[1]
assert bob.request('/api.php?action=status&server_id='+str(server_id))[0]==400
status,body=bob.action(server_id,'start');assert 'nincs hozzá jogosultságod' in body
status,body=alice.request('/?server='+str(server_id));assert 'name="command"' in body and 'SSH_PRIVATE_KEY_PATH' not in body
assert json.loads(alice.request('/api.php?action=status&server_id='+str(server_id))[1])['state']=='inactive'
assert 'vezérlési parancs lefutott' in alice.action(server_id,'start')[1]
assert json.loads(alice.request('/api.php?action=status&server_id='+str(server_id))[1])['state']=='active'
command='say Hello ; $(id)'
assert 'parancsot továbbítottuk' in alice.action(server_id,'command',command)[1]
logs=json.loads(alice.request('/api.php?action=logs&server_id='+str(server_id))[1])['logs']
assert 'Executed: '+command in logs,logs
assert 'egy sorból állhat' in alice.action(server_id,'command','list\nstop')[1]
assert 'vezérlési parancs lefutott' in alice.action(server_id,'restart')[1]
assert 'vezérlési parancs lefutott' in alice.action(server_id,'stop')[1]
assert json.loads(alice.request('/api.php?action=status&server_id='+str(server_id))[1])['state']=='inactive'
assert command not in admin.request('/admin.php?section=audit')[1]
assert 'nodes_save' not in alice.request('/?page=history')[1]
# Reassignment revokes the previous owner's access on the very next request.
admin.admin('servers',dict(server,id=server_id,user_id=bob_id))
assert alice.request('/api.php?action=logs&server_id='+str(server_id))[0]==400
assert 'name="command"' in bob.request('/?server='+str(server_id))[1]
assert 'Előbb rendeld' in admin.admin('users',{'action':'delete','id':bob_id,'confirm':'1'})[1]
# An admin reset invalidates existing client sessions.
admin.admin('users',{'action':'save','id':bob_id,'email':'bob@example.com','password':'Reset-password-123'})
assert bob.request('/api.php?action=status&server_id='+str(server_id))[0]==401
bob.login('bob@example.com','Reset-password-123')
status,body=bob.request('/?page=account',{'csrf':bob.csrf(),'action':'account','email':'newbob@example.com','current_password':'Reset-password-123','new_password':'Client-changed-password-123','password_confirm':'Client-changed-password-123'})
assert status==200 and 'newbob@example.com' in body
# Protected delete and last-admin guards.
owner_id=record_id(admin.request('/admin.php?section=users')[1],'owner@example.com')
owner_response=admin.admin('users',{'action':'save','id':owner_id,'email':'owner@example.com','is_admin':'0'})[1]
assert 'saját adminjogodat' in owner_response or 'utolsó admin' in owner_response
for sid in (server_id,second_id):
    assert 'kivettük a panelből' in admin.admin('servers',{'action':'delete','id':sid,'confirm':'1'})[1]
assert 'töröltük' in admin.admin('nodes',{'action':'delete','id':node_id,'confirm':'1'})[1]
assert 'töröltük' in admin.admin('users',{'action':'delete','id':alice_id,'confirm':'1'})[1]
for path in ('/config/local.json','/config/ssh-private.pem','/lib/smarty/src/Smarty.php'):
    assert admin.request(path)[0]==404
print('HTTP workflow passed: admin CRUD, client isolation, CSRF, host-key pinning, actual SSH authentication, start/stop/restart, tmux console input/logs, reassignments, session invalidation and account change. Systemctl/journalctl were controlled fixture programs, not real games.')
