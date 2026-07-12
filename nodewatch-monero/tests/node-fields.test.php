<?php
define( 'ABSPATH', __DIR__ . '/' );
foreach ( array( '__', 'esc_html__', 'esc_attr__' ) as $fn ) { if ( ! function_exists( $fn ) ) { eval( 'function ' . $fn . '( $s, $d = null ) { return $s; }' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); } }
require_once __DIR__ . '/../includes/class-xmrpay-node-config.php';
require_once __DIR__ . '/../includes/class-xmrpay-node-fields.php';
$pass=0;$fail=0; function ok($n,$c){global $pass,$fail;$c?$pass++:$fail++;echo($c?'PASS  ':'FAIL  ').$n."\n";}
$rows=array(array('url'=>'https://node.test/?x=<bad>','auth'=>'basic','username'=>'alice" onfocus="bad','password'=>'top-secret'));
$html=XmrPay_Node_Fields::render($rows,'node_configs','xmrpay');
ok('renders indexed structured controls',strpos($html,'name="node_configs[0][url]"')!==false&&strpos($html,'name="node_configs[0][auth]"')!==false);
ok('renders URL auth and username safely',strpos($html,'https://node.test/?x=&lt;bad&gt;')!==false&&strpos($html,'value="basic" selected')!==false&&strpos($html,'alice&quot; onfocus=&quot;bad')!==false);
ok('never renders password',strpos($html,'top-secret')===false&&strpos($html,'name="node_configs[0][password]"')!==false&&strpos($html,'data-password-saved="true"')!==false);
ok('saved password gets non-secret guidance',strpos($html,'Password saved')!==false);
ok('renders add and remove controls',strpos($html,'xmrpay-add-node')!==false&&strpos($html,'xmrpay-remove-node')!==false&&strpos($html,'Add another node')!==false);
ok('renders a numbered accessible node card',strpos($html,'xmrpay-node-card')!==false&&strpos($html,'xmrpay-node-title')!==false&&strpos($html,'Node 1')!==false&&strpos($html,'Node URL')!==false&&strpos($html,'Authentication')!==false);
ok('renders an idle per-node status chip',strpos($html,'xmrpay-node-status')!==false&&strpos($html,'Not checked')!==false);
$legacy=XmrPay_Node_Fields::render('https://legacy.test','node_configs','xmrpay');
ok('legacy nodes render with None auth',strpos($legacy,'value="none" selected')!==false);
$gateway=file_get_contents(__DIR__.'/../includes/class-wc-gateway-xmrpay.php');
$setup=file_get_contents(__DIR__.'/../includes/class-xmrpay-setup.php');
ok('gateway always persists sanitized node settings',strpos($gateway,"if ( null !== \$nodes ) { update_option(")!==false);
ok('gateway settings enqueue shared row assets',strpos($gateway,"assets/node-fields.js")!==false&&strpos($gateway,"assets/node-fields.css")!==false);
ok('node field CSS has its own cache revision',strpos($gateway,"XMRPAY_WC_VERSION . '-node-fields-3'")!==false&&strpos($setup,"XMRPAY_WC_VERSION . '-node-fields-3'")!==false);
$admin=file_get_contents(__DIR__.'/../assets/admin.js');
$nodejs=file_get_contents(__DIR__.'/../assets/node-fields.js');
$nodecss=file_get_contents(__DIR__.'/../assets/node-fields.css');
ok('production scanner prefers structured node configs',strpos($gateway,"\$nodes = \$this->get_option( 'node_configs' );")!==false);
ok('full settings check submits structured node rows',strpos($admin,"node_configs:JSON.stringify(rows)")!==false&&strpos($admin,"woocommerce_xmrpay_nodes")===false);
ok('full settings collector reads rendered credential fields',strpos($admin,"[name$=\"[username]\"]")!==false&&strpos($admin,"[name$=\"[password]\"]")!==false&&strpos($admin,'.xmrpay-node-username')===false&&strpos($admin,'.xmrpay-node-password')===false);
ok('dynamic rows visibly renumber and expose health helpers',strpos($nodejs,'.xmrpay-node-title')!==false&&strpos($nodejs,'index + 1')!==false&&strpos($nodejs,'setChecking')!==false&&strpos($nodejs,'applyResults')!==false);
ok('node fields lock during checks and new rows reset status',strpos($nodejs,'field.disabled = busy')!==false&&strpos($nodejs,"list.appendChild(copy); setState(copy, 'idle')")!==false);
ok('node cards use separated responsive layout',strpos($nodecss,'.xmrpay-node-card')!==false&&strpos($nodecss,'grid-template-columns')!==false&&strpos($nodecss,'border')!==false&&strpos($nodecss,'@media')!==false);
ok('WooCommerce field sizing cannot overflow cards',strpos($nodecss,'width:100%!important')!==false&&strpos($nodecss,'min-width:0!important')!==false);
ok('settings shows a live elapsed timer during checks',strpos($admin,'setInterval')!==false&&strpos($admin,'formatElapsed')!==false&&strpos($admin,'setChecking')!==false&&strpos($admin,'applyResults')!==false);
echo "\n".($fail?'FAILED':'ALL GREEN').": $pass passed, $fail failed\n"; exit($fail?1:0);
