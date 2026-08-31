Clazz.declarePackage("JS");
Clazz.load(["JU.M4"], "JS.CLEG", ["java.util.Arrays", "JU.AU", "$.BS", "$.P3", "$.PT", "JS.SV", "JS.SpaceGroup", "$.UnitCell", "JV.Viewer"], function(){
var c$ = Clazz.declareType(JS, "CLEG", null);
/*LV!1824 unnec constructor*/Clazz.defineMethod(c$, "transformSpaceGroup", 
function(vwr, bs, cleg, paramsOrUC, sb){
var sym0 = vwr.getCurrentUnitCell();
var sym = vwr.getOperativeSymmetry();
if (sym0 != null && sym !== sym0) sym.getUnitCell(sym0.getV0abc(null, null), false, "modelkit");
if (cleg == null) {
return "!no CLEG id for this space group";
}if (cleg.indexOf("<") >= 0) {
cleg = JU.PT.rep(cleg, "<<", ">super>").$replace('<', '>');
}if (cleg.length == 0 || cleg.endsWith(">")) cleg += ".";
var data =  new JS.CLEG.ClegData(vwr.getSymTemp(), JU.PT.split(cleg, ">"));
var asgParams =  new JS.CLEG.AssignedSGParams(vwr, sym, bs, paramsOrUC, 0, false, sb, cleg.equals("."));
var ret = JS.CLEG.assignSpaceGroup(data, asgParams);
if (ret.endsWith("!")) return ret;
if (asgParams.mkIsAssign) sb.append(ret);
return ret;
}, "JV.Viewer,JU.BS,~S,~O,JU.SB");
c$.assignSpaceGroup = Clazz.defineMethod(c$, "assignSpaceGroup", 
function(data, asgParams){
var vwr = asgParams.vwr;
var index = asgParams.mkIndex;
var tokens = data.tokens;
var initializing = (index < 0);
if (initializing) {
index = 0;
}if (index >= tokens.length) return "invalid CLEG expression!";
var haveUCParams = (asgParams.mkParamsOrUC != null);
if (tokens.length > 1 && haveUCParams) {
return "invalid syntax - can't mix transformations and UNITCELL option!";
}if (index == 0 && !initializing) JS.CLEG.standardizeTokens(tokens, false);
var isFinal = (index == tokens.length - 1);
var token = tokens[index].trim();
var isDot = token.equals(".");
var nextTransformExplicit = (isFinal ? isDot : JS.CLEG.isTransformOnly(tokens[index + 1]) && tokens[index + 1].length > 0);
if (index == 0) {
if (token.length == 0 || isDot) {
if (asgParams.mkSym00 == null) {
return "no starting space group for CLEG!";
}if (!asgParams.mkIsAssign) {
tokens[0] = asgParams.mkSym00.getSpaceGroupClegId();
asgParams.mkIndex = (isDot && isFinal ? 0 : 1);
asgParams.mkWasNode = (asgParams.mkIndex == 1);
asgParams.mkIgnoreAllSettings = nextTransformExplicit;
return JS.CLEG.assignSpaceGroup(data, asgParams);
}}if (asgParams.mkIsAssign) {
if (asgParams.mkSym00 == null) return "no starting space group for calculation!";
}}var isCalc = (JS.CLEG.getCalcType(token) != null);
if ((isCalc || JS.CLEG.isTransformOnly(token)) != asgParams.mkWasNode) {
return "invalid CLEG expression, not node>transform>node>transform>....!";
}asgParams.mkWasNode = !asgParams.mkWasNode;
var calcNext = (isCalc ? token : null);
if (isCalc) {
token = tokens[++index].trim();
isFinal = (index == tokens.length - 1);
}if (isFinal && isDot && data.getPrevNode() != null) {
token = data.getPrevNode().getCleanITAName();
}var pt = token.lastIndexOf(":");
var zapped = (asgParams.mkSym00 == null);
var isUnknown = false;
var haveTransform = JS.CLEG.isTransform(token, false);
var isSetting = (haveTransform && pt >= 0);
var isTransformOnly = (haveTransform && !isSetting);
var restarted = false;
var ignoreNodeTransform = false;
var ignoreFirstSetting = false;
var sym = data.sym;
var node = null;
if (!isTransformOnly) {
data.setSymmetry(sym);
node =  new JS.CLEG.ClegNode(data, index, token);
if (data.errString != null) return data.errString;
}if (data.getPrevNode() == null) {
if (!JS.CLEG.checkFullSyntax(tokens, sym, JS.CLEG.allow300)) return "invalid CLEG expression!";
if (!asgParams.mkCalcOnly && !isTransformOnly && zapped && !node.isDefaultSetting()) {
var ita = node.myIta;
var cleg =  Clazz.newArray(-1, [node.specialPrefix + ita]);
var paramsInit = (asgParams.mkCalcOnly ?  new JS.CLEG.AssignedSGParams(vwr, false) :  new JS.CLEG.AssignedSGParams(vwr, null, null, asgParams.mkParamsOrUC, -1, true, asgParams.mkSb, false));
var cdInit =  new JS.CLEG.ClegData(vwr.getSymTemp(), cleg);
var err = JS.CLEG.assignSpaceGroup(cdInit, paramsInit);
if (err.endsWith("!")) return err;
if (asgParams.mkCalcOnly) {
sym = asgParams.mkSym00 = cdInit.sym;
data.trMat = cdInit.trMat;
} else {
asgParams.mkSym00 = vwr.getOperativeSymmetry();
}if (asgParams.mkSym00 == null) return "CLEG spacegroup initialization error!";
zapped = false;
restarted = true;
}ignoreFirstSetting = (index == 0 && (!asgParams.mkCalcOnly && !zapped && nextTransformExplicit || asgParams.mkIsAssign));
var ita0 = (zapped ? null : asgParams.mkSym00.getSpaceGroupClegId());
var trm0 = null;
if (!zapped) {
if (ita0 == null || ita0.equals("0")) {
} else {
var pt1 = ita0.indexOf(":");
if (pt1 > 0) {
trm0 = ita0.substring(pt1 + 1);
ita0 = ita0.substring(0, pt1);
pt1 = -1;
}if (ignoreFirstSetting) {
trm0 = asgParams.mkSym00.getSpaceGroupInfoObj("itaTransform", null, false, false);
}}}if (data.trMat == null) {
data.trMat =  new JU.M4();
data.trMat.setIdentity();
}if (!asgParams.mkIsAssign) {
data.setSymmetry(sym);
data.setPrevNode( new JS.CLEG.ClegNode(data, -1, ita0 == null ? null : ita0 + ":" + trm0));
if (asgParams.mkIgnoreAllSettings) data.getPrevNode().disable();
if (!data.getPrevNode().update(data)) return data.errString;
}}if (isCalc) {
if (!data.getPrevNode().setCalcNext(data, calcNext)) {
return data.errString;
}}if (isTransformOnly) {
if (isFinal) {
isUnknown = true;
}if (token.length > 0) data.addTransform(index, token);
++asgParams.mkIndex;
if (!isUnknown) return JS.CLEG.assignSpaceGroup(data, asgParams);
}if (!ignoreFirstSetting) {
data.setSymmetry(sym);
if (ignoreNodeTransform) node.disable();
if (!node.update(data)) return data.errString;
if (isFinal && isDot) {
data.setNodeTransform(node);
}data.updateTokens(node);
}if (!isFinal) {
asgParams.mkIndex++;
data.setPrevNode(node);
return JS.CLEG.assignSpaceGroup(data, asgParams);
}var params = null;
var oabc = null;
var origin = null;
params = (!haveUCParams || !JU.AU.isAD(asgParams.mkParamsOrUC) ? null : asgParams.mkParamsOrUC);
if (zapped) {
sym.setUnitCellFromParams(params == null ?  Clazz.newFloatArray(-1, [10, 10, 10, 90, 90, 90]) : params, false, NaN);
asgParams.mkParamsOrUC = null;
haveUCParams = false;
}if (haveUCParams) {
if (JU.AU.isAD(asgParams.mkParamsOrUC)) {
params = asgParams.mkParamsOrUC;
} else {
oabc = asgParams.mkParamsOrUC;
origin = oabc[0];
}} else if (!zapped) {
data.setSymmetry(sym = asgParams.mkSym00);
if (data.trMat == null) {
data.trMat =  new JU.M4();
data.trMat.setIdentity();
}oabc = sym.getV0abc( Clazz.newArray(-1, [data.trMat]), null);
origin = oabc[0];
}if (oabc != null) {
params = sym.getUnitCell(oabc, false, "assign").getUnitCellParams();
if (origin == null) origin = oabc[0];
}var isP1 = (token.equalsIgnoreCase("P1") || token.equals("ITA/1.1"));
try {
var bsAtoms;
var noAtoms;
var modelIndex = -1;
if (asgParams.mkCalcOnly) {
asgParams.mkBitset = bsAtoms =  new JU.BS();
noAtoms = true;
} else {
if (asgParams.mkBitset != null && asgParams.mkBitset.isEmpty()) return "no atoms specified!";
bsAtoms = vwr.getThisModelAtoms();
var bsCell = (isP1 ? bsAtoms : JS.SV.getBitSet(vwr.evaluateExpressionAsVariable("{within(unitcell)}"), true));
if (asgParams.mkBitset == null) {
asgParams.mkBitset = bsAtoms;
}if (asgParams.mkBitset != null) {
bsAtoms.and(asgParams.mkBitset);
if (!isP1) bsAtoms.and(bsCell);
}noAtoms = bsAtoms.isEmpty();
modelIndex = (noAtoms && vwr.am.cmi < 0 ? 0 : noAtoms ? vwr.am.cmi : vwr.ms.at[bsAtoms.nextSetBit(0)].getModelIndex());
if (!asgParams.mkIsAssign) vwr.ms.getModelAuxiliaryInfo(modelIndex).remove("spaceGroupInfo");
}if (!zapped && !asgParams.mkIsAssign) {
sym.saveOrRetrieveTransformMatrix(data.trMat);
}if (params == null) params = sym.getUnitCellMultiplied().getUnitCellParams();
isUnknown = new Boolean (isUnknown | asgParams.mkIsAssign).valueOf();
var sgInfo = (noAtoms && isUnknown ? null : vwr.findSpaceGroup(sym, isUnknown ? bsAtoms : null, isUnknown ? null : node.getName(), params, origin, oabc, 2 | (asgParams.mkCalcOnly ? 16 : 0) | (!zapped || asgParams.mkIsAssign ? 0 : 4)));
if (sgInfo == null) {
return "Space group " + node.getName() + " is unknown or not compatible!";
}if (oabc == null || zapped) oabc = sgInfo.get("unitcell");
token = sgInfo.get("name");
var jmolId = sgInfo.get("jmolId");
var basis = sgInfo.get("basis");
var sg = sgInfo.remove("sg");
sym.getUnitCell(oabc, false, null);
sym.setSpaceGroupTo(sg == null ? jmolId : sg);
sym.setSpaceGroupName(token);
if (asgParams.mkCalcOnly) {
data.setSymmetry(sym);
return "OK";
}if (basis == null) {
basis = sym.removeDuplicates(vwr.ms, bsAtoms, true);
}vwr.ms.setSpaceGroup(modelIndex, sym, basis);
if (asgParams.mkIsAssign) {
return token;
}if (zapped || restarted) {
vwr.runScript("unitcell on; center unitcell;axes unitcell; axes 0.1; axes on;set perspectivedepth false;moveto 0 axis c1;draw delete;show spacegroup");
}var finalTransform = data.abcFor(data.trMat);
tokens[index] = sg.getClegId();
if (!initializing) {
JS.CLEG.standardizeTokens(tokens, true);
var msg = JU.PT.join(tokens, '>', 0) + (basis.isEmpty() ? "" : "\n basis=" + basis);
System.out.println("CLEG=" + msg);
asgParams.mkSb.append(msg).append("\n");
}return finalTransform;
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
if (!JV.Viewer.isJS) e.printStackTrace();
return e.getMessage() + "!";
} else {
throw e;
}
}
}, "JS.CLEG.ClegData,JS.CLEG.AssignedSGParams");
c$.standardizeTokens = Clazz.defineMethod(c$, "standardizeTokens", 
function(tokens, isEnd){
for (var i = tokens.length; --i >= 0; ) {
var t = tokens[i];
if (t.length == 0) {
continue;
}t = JS.CLEG.cleanCleg000(t);
if (t.endsWith(":h")) {
if (!t.startsWith("R")) t = t.substring(0, t.length - 2);
} else if (!isEnd && t.endsWith(":2/3a+1/3b+1/3c,-1/3a+1/3b+1/3c,-1/3a-2/3b+1/3c")) {
} else if (t.endsWith(":r")) {
if (!t.startsWith("R")) t = t.substring(0, t.length - 1) + "2/3a+1/3b+1/3c,-1/3a+1/3b+1/3c,-1/3a-2/3b+1/3c";
} else if (t.equals("r")) {
t = "2/3a+1/3b+1/3c,-1/3a+1/3b+1/3c,-1/3a-2/3b+1/3c";
} else if (t.equals("h")) {
t = "a-b,b-c,a+b+c";
}tokens[i] = t;
}
System.out.println("MK StandardizeTokens " + java.util.Arrays.toString(tokens));
}, "~A,~B");
c$.cleanCleg000 = Clazz.defineMethod(c$, "cleanCleg000", 
function(t){
return (t.endsWith(";0,0,0") ? t.substring(0, t.length - 6) : t);
}, "~S");
c$.isProbableClegSetting = Clazz.defineMethod(c$, "isProbableClegSetting", 
function(name){
var p = name.indexOf(":");
var type = JS.SpaceGroup.getExplicitSpecialGroupType(name);
return (type >= 0 && p > 0 && JS.SpaceGroup.getITNo(type == 0 ? name : name.substring(2), p) > 0 && name.indexOf(",") > p ? p : 0);
}, "~S");
c$.checkFullSyntax = Clazz.defineMethod(c$, "checkFullSyntax", 
function(tokens, sym, allow300){
for (var i = 0; i < tokens.length; i++) {
var s = tokens[i].trim();
if (s == null || s.length == 0 || s.startsWith("sub") || s.startsWith("super")) continue;
var groupType = JS.SpaceGroup.getExplicitSpecialGroupType(s);
if (groupType > 0) s = s.substring(2);
var ptColon = s.indexOf(":");
var ptComma = s.indexOf(",", ptColon + 1);
var ptDot = s.indexOf(".");
var isQuest = (s.indexOf("?:") == 0 || s.indexOf(".:") == 0 || s.indexOf("0:") == 0);
var isClegSetting = (ptColon > 0 && ptComma > ptColon);
var ptHall = s.indexOf("]");
var isHall = (ptHall > 0 && s.charAt(0) == '[' && (ptColon < 0 || ptColon == ptHall + 1));
var itno = (isQuest ? 0 : isHall ? 1 : JU.PT.parseFloatStrict(ptColon > 0 ? s.substring(0, ptColon) : s));
if (Float.isNaN(itno)) {
if (ptDot > 0 || isClegSetting) return false;
} else if (!isQuest && !JS.SpaceGroup.isInRange(itno, groupType, !isClegSetting, allow300 && groupType == 0)) {
return false;
}if (ptComma < 0) continue;
var transform = (ptColon > 0 ? s.substring(ptColon + 1) : s);
if ((sym.convertTransform(transform, null)).determinant3() == 0) return false;
}
return true;
}, "~A,J.api.SymmetryInterface,~B");
c$.getCalcType = Clazz.defineMethod(c$, "getCalcType", 
function(token){
return (token.length == 0 || token.equals("sub") ? "sub" : token.charAt(0) != 's' ? null : token.startsWith("sub(") ? "sub(" : token.equals("super") ? "super" : token.startsWith("super(") ? "super(" : null);
}, "~S");
c$.isTransformOnly = Clazz.defineMethod(c$, "isTransformOnly", 
function(token){
return (JS.CLEG.isTransform(token, false) && token.indexOf(":") < 0);
}, "~S");
c$.isTransform = Clazz.defineMethod(c$, "isTransform", 
function(token, checkColonRH){
return (token.length == 0 || token.indexOf(',') > 0 || "!r!h".indexOf(token) >= 0) || checkColonRH && (token.endsWith(":r") || token.endsWith(":h"));
}, "~S,~B");
Clazz.defineMethod(c$, "getMatrixTransform", 
function(vwr, cleg, retLstOrMap){
if (cleg.length == 0) cleg = ".";
if (cleg.indexOf(">") < 0 && !cleg.equals(".")) cleg = ">>" + cleg;
var tokens = JU.PT.split(cleg, ">");
if (tokens[0].length == 0) tokens[0] = "ref";
var data =  new JS.CLEG.ClegData(vwr.getSymTemp(), tokens);
var retMap = (Clazz.instanceOf(retLstOrMap,"java.util.Map") ? retLstOrMap : null);
var retLst = (retMap == null && Clazz.instanceOf(retLstOrMap,"JU.Lst") ? retLstOrMap : null);
data.setReturnMap(retMap);
data.setReturnLst(retLst);
var err = JS.CLEG.assignSpaceGroup(data,  new JS.CLEG.AssignedSGParams(vwr, cleg.equals(".")));
if (err.indexOf("!") > 0) {
System.err.println(err);
if (retMap != null) retMap.put("error", err);
return null;
}if (retLst == null && retMap == null) {
System.out.println("CLEG transform: " + JU.PT.join(tokens, '>', 0));
cleg = data.abcFor(data.trMat);
System.out.println("CLEG transform: " + tokens[0] + ">" + cleg + ">" + tokens[tokens.length - 1]);
}return data.trMat;
}, "JV.Viewer,~S,~O");
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.tokens = null;
this.sym = null;
this.trMat = null;
this.errString = null;
this.trLink = null;
this.trTemp = null;
this.prevNode = null;
this.retMap = null;
this.retLst = null;
this.asM4 = false;
Clazz.instantialize(this, arguments);}, JS.CLEG, "ClegData", null);
Clazz.prepareFields (c$, function(){
this.trTemp =  new JU.M4();
});
Clazz.makeConstructor(c$, 
function(sym, tokens){
this.tokens = tokens;
this.sym = sym;
}, "J.api.SymmetryInterface,~A");
Clazz.defineMethod(c$, "setSymmetry", 
function(sym){
this.sym = sym;
}, "J.api.SymmetryInterface");
Clazz.defineMethod(c$, "addSGTransform", 
function(tr, what){
if (this.trMat == null) {
System.out.println("ClegData reset");
this.trMat =  new JU.M4();
this.trMat.setIdentity();
}if (tr != null) {
this.trMat.mul(this.matFor(tr));
}if (what != null) System.out.println("ClegData adding " + what + " " + tr + " now " + this.abcFor(this.trMat));
return this.trMat;
}, "~S,~S");
Clazz.defineMethod(c$, "abcFor", 
function(trm){
return this.sym.staticGetTransformABC(trm, false);
}, "JU.M4");
Clazz.defineMethod(c$, "matFor", 
function(trm){
return this.sym.convertTransform(trm, (trm.indexOf(">") > 0 ? null : this.trTemp));
}, "~S");
Clazz.defineMethod(c$, "removePrevNodeTrm", 
function(){
if (this.prevNode != null && this.prevNode.myTrm != null && !this.prevNode.disabled) {
this.addSGTransform("!" + this.prevNode.myTrm, "!prevNode.myTrm");
}});
Clazz.defineMethod(c$, "calculate", 
function(trm0){
trm0.invert();
var trm1 = JU.M4.newM4(this.trMat);
trm1.mul(trm0);
return this.sym.convertTransform(null, trm1);
}, "JU.M4");
Clazz.defineMethod(c$, "updateTokens", 
function(node){
var index = node.index;
var s = node.name;
if (s.startsWith("ITA/")) s = s.substring(4);
 else s = (node.myIta == null ? "." : node.myIta) + ":" + node.myTrm;
this.setToken(index, s);
if (node.calculated != null && index > 0) this.setToken(index - 1, node.calculated);
}, "JS.CLEG.ClegNode");
Clazz.defineMethod(c$, "setToken", 
function(index, s){
this.tokens[index] = s;
}, "~N,~S");
Clazz.defineMethod(c$, "addTransformLink", 
function(){
if (this.trLink == null) {
this.trLink =  new JU.M4();
this.trLink.setIdentity();
}this.trLink.mul(this.trTemp);
});
Clazz.defineMethod(c$, "setNodeTransform", 
function(node){
node.myTrm = this.abcFor(this.trMat);
node.setITAName(node.name);
}, "JS.CLEG.ClegNode");
Clazz.defineMethod(c$, "addTransform", 
function(index, transform){
this.addSGTransform(transform, ">t>");
this.addTransformLink();
System.out.println("CLEG.addTransform index=" + index + " trm=" + this.trMat);
}, "~N,~S");
Clazz.defineMethod(c$, "getPrevNode", 
function(){
return this.prevNode;
});
Clazz.defineMethod(c$, "setPrevNode", 
function(node){
return this.prevNode = node;
}, "JS.CLEG.ClegNode");
Clazz.defineMethod(c$, "addPrimitiveTransform", 
function(myIta, myTrm){
var hmName = this.sym.getSpaceGroupInfoObj("hmName", myIta + ":" + myTrm, false, false);
if (hmName == null) return myTrm;
var c = hmName.charAt(0);
if ("ABCFI".indexOf(c) < 0) return myTrm;
var t = JU.M4.newMV(JS.UnitCell.getPrimitiveTransform(c), JU.P3.new3(0, 0, 0));
t.mul(this.matFor(myTrm));
return this.abcFor(t);
}, "~S,~S");
Clazz.defineMethod(c$, "setReturnMap", 
function(ret){
if (ret != null) {
this.asM4 = (ret.get("ASM4") === Boolean.TRUE);
ret.clear();
}this.retMap = ret;
}, "java.util.Map");
Clazz.defineMethod(c$, "setReturnLst", 
function(ret){
if (ret != null) ret.clear();
this.retLst = ret;
}, "JU.Lst");
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.name = null;
this.myIta = null;
this.myTrm = null;
this.index = 0;
this.calcNext = null;
this.calcI1 = 0;
this.calcI2 = 0;
this.calcDepthMin = 0;
this.calcDepthMax = 0;
this.calcIndexMin = 0;
this.calcIndexMax = 0;
this.calculated = null;
this.disabled = false;
this.isThisModelCalc = false;
this.hallSymbol = null;
this.specialType = 0;
this.specialPrefix = "";
Clazz.instantialize(this, arguments);}, JS.CLEG, "ClegNode", null);
Clazz.makeConstructor(c$, 
function(data, index, name){
if (name == null) return;
this.index = index;
this.init(data, name);
}, "JS.CLEG.ClegData,~N,~S");
Clazz.defineMethod(c$, "disable", 
function(){
this.disabled = true;
});
Clazz.defineMethod(c$, "checkSpecial", 
function(name){
switch (this.specialType = JS.SpaceGroup.getExplicitSpecialGroupType(name)) {
case -1:
return null;
case 0:
if (!JS.CLEG.allow300) return name;
var ptDot = name.indexOf(".");
var sname = (ptDot > 0 ? name.substring(0, ptDot) : name);
var itno = JS.SpaceGroup.getITNo(sname, 0);
if (itno < 300) return name;
if (itno > 600) return null;
this.specialType = (Clazz.doubleToInt(itno / 100)) * 100;
this.specialPrefix = JS.SpaceGroup.getGroupTypePrefix(itno);
return "" + (itno - this.specialType) + name.substring(sname.length);
default:
this.specialPrefix = name.substring(0, 2);
return name.substring(2);
}
}, "~S");
Clazz.defineMethod(c$, "init", 
function(data, name){
var pt = JS.CLEG.isProbableClegSetting(name);
if (pt > 0) {
this.myIta = name.substring(0, pt);
this.myTrm = name.substring(pt + 1);
}if (name.equals("ref")) {
this.isThisModelCalc = true;
}name = this.checkSpecial(name);
var isPrimitive = name.endsWith(":p");
if (isPrimitive) name = name.substring(0, name.length - 2);
var isITAnDotm = name.startsWith("ITA/");
if (isITAnDotm) {
name = this.checkSpecial(name.substring(4));
}var isHM = false;
this.hallSymbol = null;
var hallTrm = null;
if (this.specialType == 0 && name.charAt(0) == '[') {
pt = name.indexOf(']');
if (pt < 0) {
data.errString = "invalid Hall symbol: " + name + "!";
return;
}this.hallSymbol = name.substring(1, pt);
pt = this.hallSymbol.indexOf("(");
if (pt > 0) {
hallTrm = this.hallSymbol.substring(pt + 1, this.hallSymbol.length - 1) + " ";
this.hallSymbol = this.hallSymbol.substring(0, pt).trim();
hallTrm = JU.PT.rep(hallTrm, " ", "/12,");
hallTrm = "a,b,c;" + hallTrm.substring(0, hallTrm.length - 1);
}pt = name.indexOf(":");
if (pt > 0) {
this.myTrm = name.substring(pt + 1);
}name = "Hall:" + this.hallSymbol;
} else if (name.startsWith("HM:")) {
isHM = true;
} else if (name.length <= 3) {
isITAnDotm = (JS.SpaceGroup.getITNo(name, 0) > 0);
if (isITAnDotm) {
name = this.checkSpecial(name) + ".1";
}}if (!isITAnDotm && this.hallSymbol == null && !isHM) {
pt = (JU.PT.isDigit(name.charAt(0)) ? name.indexOf(" ") : -1);
if (pt > 0) name = name.substring(0, pt);
if (name.indexOf('.') > 0 && !Float.isNaN(JU.PT.parseFloat(name))) {
isITAnDotm = true;
}}if (isITAnDotm) {
this.myTrm = (name.endsWith(".1") ? "a,b,c" : data.sym.getSpaceGroupInfoObj("itaTransform", this.specialPrefix + name, false, false));
if (this.myTrm == null) {
data.errString = "Unknown ITA setting: " + this.specialPrefix + name + "!";
return;
}var parts = JU.PT.split(name, ".");
this.myIta = parts[0];
} else {
if (this.myIta == null) this.myIta = data.sym.getSpaceGroupInfoObj("itaNumber", this.specialPrefix + name, false, false);
if (this.myTrm == null) this.myTrm = data.sym.getSpaceGroupInfoObj("itaTransform", this.specialPrefix + name, false, false);
if (this.hallSymbol != null && hallTrm != null) {
this.myTrm = hallTrm + (this.myTrm.equals("a,b,c") ? "" : ">" + this.myTrm);
}}if ("0".equals(this.myIta)) {
data.errString = "Could not get ITA space group for " + name + "!";
return;
}if (isPrimitive) {
this.myTrm = data.addPrimitiveTransform(this.myIta, this.myTrm);
}this.setITAName(name);
}, "JS.CLEG.ClegData,~S");
Clazz.defineMethod(c$, "setITAName", 
function(name){
return this.name = (".".equals(name) || this.myIta == null ? "." : "ITA/" + this.specialPrefix + this.myIta) + (this.myTrm == null ? "" : ":" + this.myTrm);
}, "~S");
Clazz.defineMethod(c$, "update", 
function(data){
if (data.errString != null) return false;
if (this.name == null) return true;
var prev = data.prevNode;
if (prev.isThisModelCalc) prev.myIta = this.myIta;
var haveReferenceCell = (data.trLink == null && (this.myIta != null && (this.myIta.equals(prev.myIta) || prev.calcNext != null)));
if (!haveReferenceCell) return true;
var trm0 = JU.M4.newM4(data.trMat);
data.removePrevNodeTrm();
var trCalc = null;
if (prev.calcNext != null) {
var isSub = true;
var isImplicit = false;
var isCalcFunction = false;
switch (prev.calcNext) {
case "super(":
case "sub(":
isCalcFunction = true;
break;
case "super":
isSub = false;
break;
case "sub":
break;
case "":
case "set":
prev.calcNext = "set";
isImplicit = true;
break;
}
var ita1 = JU.PT.parseInt(prev.myIta);
var ita2 = JU.PT.parseInt(this.myIta);
var unspecifiedSettingChangeOnly = !isCalcFunction && (data.retLst == null && (data.retMap == null || data.asM4) && isImplicit && ita1 == ita2);
if (!unspecifiedSettingChangeOnly) {
var flags = (prev.calcIndexMax << 24) | (prev.calcIndexMin << 16) | (prev.calcDepthMax << 8) | prev.calcDepthMin;
trCalc = data.sym.getSubgroupJSON((isSub ? prev.name : this.name), (isSub ? this.name : prev.name), prev.calcI1, prev.calcI2, flags, data.retMap, data.retLst);
var haveCalc = (trCalc != null);
if (haveCalc) {
if (trCalc.endsWith("!")) {
data.errString = trCalc;
return false;
}if (!isSub) trCalc = "!" + trCalc;
}var calc = prev.myIta + ">" + (haveCalc ? trCalc : "?") + ">" + this.myIta;
if (!haveCalc) {
data.errString = calc + "!";
return false;
}data.addSGTransform(trCalc, "sub");
}}if (!this.disabled) data.addSGTransform(this.myTrm, "myTrm");
this.calculated = data.calculate(trm0);
System.out.println("calculated is " + this.calculated + (data.retMap == null ? "" : " for the path " + data.retMap.get("indexPath")));
return true;
}, "JS.CLEG.ClegData");
Clazz.defineMethod(c$, "getName", 
function(){
return this.name;
});
Clazz.defineMethod(c$, "getCleanITAName", 
function(){
if (this.name == null) return (this.name = ".");
var s = (this.name.startsWith("ITA/") ? this.name.substring(4) : this.name);
if (this.specialType != 0 && !s.startsWith(this.specialPrefix)) s = this.specialPrefix + s;
return s;
});
Clazz.defineMethod(c$, "isDefaultSetting", 
function(){
return (this.myTrm == null || JS.CLEG.cleanCleg000(this.myTrm).equals("a,b,c"));
});
Clazz.defineMethod(c$, "setCalcNext", 
function(data, token){
var pt = token.length;
switch (pt == 0 ? token : JS.CLEG.getCalcType(token)) {
case "sub":
if (data.retLst != null || data.retMap != null) {
pt = 0;
break;
}case "super":
this.calcI1 = 1;
this.calcI2 = 1;
pt = 3;
break;
case "sub(":
pt = -3;
break;
case "super(":
pt = -5;
break;
}
var isErr = true;
while (true) {
if (pt == 0) {
this.calcIndexMin = 2;
this.calcIndexMax = 0xFF;
this.calcDepthMin = 1;
this.calcDepthMax = 0xFF;
isErr = false;
} else if (pt > 0) {
this.calcIndexMin = 2;
this.calcIndexMax = 0xFF;
this.calcDepthMin = 1;
this.calcDepthMax = 1;
isErr = false;
} else {
if (token.indexOf(")") != token.length - 1) break;
var params = JU.PT.split(JU.PT.trim(token.toLowerCase().substring(-pt + 1), ")"), ",");
try {
if (token.length == 5 || token.indexOf('=') >= 0) {
this.calcIndexMin = 2;
this.calcIndexMax = 0xFF;
this.calcDepthMin = 1;
this.calcDepthMax = 0xFF;
token = token.substring(0, -pt);
for (var i = params.length; --i >= 0; ) {
var p = params[i];
var val = Math.min(0xFF, Integer.parseInt(p.substring(p.indexOf('=') + 1)));
if (p.startsWith("indexmax=")) this.calcIndexMax = Math.max(2, val);
 else if (p.startsWith("indexmin=")) this.calcIndexMin = Math.max(2, val);
 else if (p.startsWith("index=")) this.calcIndexMin = this.calcIndexMax = Math.max(2, val);
if (p.startsWith("depthmax=")) this.calcDepthMax = Math.max(1, val);
 else if (p.startsWith("depthmin=")) this.calcDepthMin = Math.max(1, val);
 else if (p.startsWith("depth=")) this.calcDepthMin = this.calcDepthMax = Math.max(1, val);
}
} else {
switch (params.length) {
case 2:
this.calcI2 = Math.max(0, Integer.parseInt(params[1])) & 0xFF;
case 1:
this.calcI1 = Math.max(1, Integer.parseInt(params[0])) & 0xFF;
break;
}
}token = token.substring(0, -pt);
isErr = false;
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
} else {
throw e;
}
}
}break;
}
if (isErr) {
data.errString = "Error parsing CLEG " + token + "!";
return false;
}this.calcNext = token;
return true;
}, "JS.CLEG.ClegData,~S");
Clazz.overrideMethod(c$, "toString", 
function(){
return "[ClegNode #" + this.index + " " + this.name + "   " + this.myIta + ":" + this.myTrm + (this.disabled ? " disabled" : "") + "]";
});
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.vwr = null;
this.mkCalcOnly = false;
this.mkIsAssign = false;
this.mkSb = null;
this.mkIgnoreAllSettings = false;
this.mkSym00 = null;
this.mkBitset = null;
this.mkParamsOrUC = null;
this.mkWasNode = false;
this.mkIndex = 0;
Clazz.instantialize(this, arguments);}, JS.CLEG, "AssignedSGParams", null);
Clazz.makeConstructor(c$, 
function(vwr, isCurrentSG){
this.vwr = vwr;
this.mkCalcOnly = true;
this.mkIsAssign = false;
this.mkSb = null;
if (isCurrentSG) this.mkSym00 = vwr.getOperativeSymmetry();
}, "JV.Viewer,~B");
Clazz.makeConstructor(c$, 
function(vwr, sym00, bs, paramsOrUC, index, ignoreAllSettings, sb, isAssign){
this.vwr = vwr;
this.mkCalcOnly = false;
this.mkIndex = index;
this.mkSym00 = sym00;
this.mkBitset = bs;
this.mkParamsOrUC = paramsOrUC;
this.mkIgnoreAllSettings = ignoreAllSettings;
this.mkSb = sb;
this.mkIsAssign = isAssign;
}, "JV.Viewer,J.api.SymmetryInterface,JU.BS,~O,~N,~B,JU.SB,~B");
/*eoif3*/})();
c$.allow300 = false;
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
