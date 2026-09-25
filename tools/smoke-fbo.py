"""End-to-end FBO check through the running HTTP service. Creates and removes only a random dedicated test cabinet;
API keys are kept in memory and never printed. Leaves synthetic label PDFs in local/ for visual/barcode verification.

Environment (defaults fit the PhpSoftBox Workspace with the emulator in local/ozon):
  OZON_BASE_URL   service URL, default http://localhost:8080
  OZON_CLI        command that runs the emulator CLI in its container, default "docker compose exec -T php-cli"
  OZON_WORKSPACE  directory to run OZON_CLI from, default two levels above the project (the Workspace root)
"""
import json,os,shlex,subprocess,urllib.request,time,secrets
from pathlib import Path
root=Path(__file__).resolve().parents[1]
base_url=os.environ.get('OZON_BASE_URL','http://localhost:8080').rstrip('/')
workspace=os.environ.get('OZON_WORKSPACE',str(root.parents[1]))
client=str(900000000000000+secrets.randbelow(9999999999))
control_file=root/'local'/('fbo-smoke-event-'+client+'.json')
base=shlex.split(os.environ.get('OZON_CLI','docker compose exec -T php-cli'))
def cli(*args):
 r=subprocess.run(base+list(args),check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,cwd=workspace)
 return json.loads(r.stdout)
cleanup=root/'local'/'fbo-smoke-cleanup.php'
cleanup.write_text('''<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$c = require dirname(__DIR__) . '/config/container.php';
$m = $c->get(PhpSoftBox\\MongoDb\\Connection\\MongoConnectionManagerInterface::class);
$id = $argv[1];
if (!preg_match('/^9[0-9]{14}$/D', $id)) { exit(2); }
$m->collection('fbo_cabinets')->deleteOne(['_id' => $id]);
$m->collection('api_tokens')->deleteMany(['client_id' => $id]);
''')
try:
 cli('php','psb','ozon:fbo:configure',client,'--file=fixtures/fbo-basic.json')
 key=cli('php','psb','ozon:token:issue',client)['api_key']
 def post(path,payload):
  r=urllib.request.Request(base_url+path,json.dumps(payload).encode(),{'Content-Type':'application/json','Client-Id':client,'Api-Key':key})
  with urllib.request.urlopen(r,timeout=10) as response:return json.load(response)
 def poll(path,payload):
  for i in range(30):
   response=post(path,payload)
   if response.get('status',response.get('result')) not in ('IN_PROGRESS','STATUS_IN_PROGRESS','InProgress'):return response
   time.sleep(.15)
  raise RuntimeError('Operation did not complete')
 def control(event):
  control_file.write_text(json.dumps(dict(eventId=secrets.token_hex(12),**event)))
  return cli('php','psb','ozon:fbo:control',client,'--file=local/'+control_file.name)
 def operation(path,status_path,payload):
  op=post(path,payload)
  result=poll(status_path,{'operation_id':op['operation_id']})
  assert result.get('status',result.get('result')) in ('SUCCESS','STATUS_SUCCESS','Success'), (path,result)
  return result
 assert len(post('/v1/roles',{})['roles'][0]['methods'])==57
 for kind in ['DIRECT','CROSSDOCK','MULTI_CLUSTER']:
  groups=[{'macrolocal_cluster_id':510001,'items':[{'sku':910001,'quantity':10}]}]
  if kind=='MULTI_CLUSTER':groups.append({'macrolocal_cluster_id':510002,'items':[{'sku':910002,'quantity':6}]})
  payload={'deletion_sku_mode':'PARTIAL'}
  payload['clusters_info' if kind=='MULTI_CLUSTER' else 'cluster_info']=groups if kind=='MULTI_CLUSTER' else groups[0]
  if kind!='DIRECT':payload['delivery_info']={'type':'DROPOFF','drop_off_warehouse':{'warehouse_id':710003,'warehouse_type':'SORTING_CENTER'}}
  method={'DIRECT':'direct','CROSSDOCK':'crossdock','MULTI_CLUSTER':'multi-cluster'}[kind]
  draft=post('/v1/draft/'+method+'/create',payload)['draft_id']
  info=poll('/v2/draft/create/info',{'draft_id':draft});assert info['status']=='SUCCESS'
  selected=[{'macrolocal_cluster_id':g['macrolocal_cluster_id']} for g in groups]
  if kind=='DIRECT':selected[0]['storage_warehouse_id']=710001
  common={'draft_id':draft,'selected_cluster_warehouses':selected,'supply_type':kind}
  dates={'date_from':time.strftime('%Y-%m-%d',time.gmtime()),'date_to':time.strftime('%Y-%m-%d',time.gmtime(time.time()+7*86400))}
  slots=post('/v2/draft/timeslot/info',common|dates)
  slot=slots['result']['drop_off_warehouse_timeslots']['days'][0]['timeslots'][0]
  assert post('/v2/draft/supply/create',common|{'timeslot':slot})['error_reasons']==[]
  status=poll('/v2/draft/supply/create/status',{'draft_id':draft});assert status['status']=='SUCCESS'
  order=post('/v3/supply-order/get',{'order_ids':[status['order_id']]})['orders'][0]
  assert len(order['supplies'])==len(groups)
  amounts=[]
  for supply in order['supplies']:
   amounts.extend(x['quantity'] for x in post('/v1/supply-order/bundle',{'bundle_ids':[supply['bundle_id']],'limit':100})['items'])
  assert sum(amounts)==(16 if kind=='MULTI_CLUSTER' else 10)
  for number,supply in enumerate(order['supplies']):
   supply_id=supply['supply_id']
   items=post('/v1/supply-order/bundle',{'bundle_ids':[supply['bundle_id']],'limit':100})['items']
   if kind=='MULTI_CLUSTER':operation('/v1/cargoes/transport/activate','/v1/cargoes/transport/activate/status',{'supply_id':supply_id,'is_transport':True})
   cargo=operation('/v1/cargoes/create','/v2/cargoes/create/info',{'supply_id':supply_id,'cargoes':[{'key':'smoke-box-'+str(number),'value':{'type':'BOX','items':[{'offer_id':item['offer_id'],'quantity':item['quantity'],'quant':item['quant']} for item in items]}}]})
   labels=operation('/v1/cargoes-label/create','/v1/cargoes-label/get',{'supply_id':supply_id})
   with urllib.request.urlopen(labels['result']['file_url'],timeout=10) as response:
    pdf=response.read(); assert pdf.startswith(b'%PDF-')
    (root/'local'/('fbo-smoke-'+kind+'-'+str(number)+'.pdf')).write_bytes(pdf)
   if kind=='MULTI_CLUSTER':
    transport=operation('/v1/cargoes/transport/create','/v1/cargoes/transport/create/status',{'supply_id':supply_id,'transport_cargoes':[{'type':'PALLET','count':1}]})
    operation('/v1/cargoes/transport/bind','/v1/cargoes/transport/bind/status',{'supply_id':supply_id,'transport_cargo_bind':[{'transport_cargo_id':transport['result']['transport_cargoes'][0]['id'],'cargo_ids':[cargo['result']['cargoes'][0]['value']['cargo_id']]}]})
    labels=operation('/v1/cargoes/label/transport/create','/v1/cargoes/label/transport/status',{'supply_id':supply_id})
    with urllib.request.urlopen(labels['result']['file_url'],timeout=10) as response:(root/'local'/('fbo-smoke-TGM-'+str(number)+'.pdf')).write_bytes(response.read())
   rules=post('/v1/cargoes/rules/get',{'supply_ids':[supply_id]})['supply_check_lists'][0]
   assert rules['is_valid_distribution_rule']['satisfied'] and rules['package_units_with_distribution_rule']['satisfied']
  operation('/v1/supply-order/pass/create','/v1/supply-order/pass/status',{'supply_order_id':order['order_id'],'vehicle':{'driver_name':'Local Driver','driver_phone':'+79990000000','vehicle_model':'Local Van','vehicle_number':'TEST001'}})
  control({'type':'state','orderId':order['order_id'],'state':'ACCEPTED_AT_SUPPLY_WAREHOUSE'})
  for supply in order['supplies']:
   sku=post('/v1/supply-order/bundle',{'bundle_ids':[supply['bundle_id']],'limit':100})['items'][0]
   control({'type':'acceptance','supplyId':supply['supply_id'],'items':[{'sku':sku['sku'],'factQuantity':sku['quantity']-1,'defectQuantity':1}]})
   assert len(post('/v1/supply-order/act/product/get',{'supply_id':supply['supply_id']})['supply_acts'])==3
  assert post('/v1/supply-order/act/summary/get',{'order_id':order['order_id']})['supplies_acts']
  print(kind+': draft/order/cargo/labels/pass/acceptance OK',flush=True)
 assert len(post('/v3/supply-order/list',{'filter':{'states':['REPORTS_CONFIRMATION_AWAITING']},'limit':100,'sort_by':'ORDER_CREATION'})['order_ids'])==3
 print('HTTP, CLI credentials, persisted three-route flow: OK')
finally:
 subprocess.run(base+['php','local/fbo-smoke-cleanup.php',client],check=True,stdout=subprocess.DEVNULL,cwd=workspace)
 cleanup.unlink()
 control_file.unlink(missing_ok=True)
 print('Exact smoke cabinet and credentials removed')
