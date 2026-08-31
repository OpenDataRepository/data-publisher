Clazz.declarePackage("JS");
Clazz.load(["java.util.Hashtable", "JU.M3", "$.V3"], "JS.PointGroup", ["JU.Lst", "$.P3", "$.PT", "$.Quat", "$.SB", "J.bspt.Bspt", "JS.SymmetryOperation", "JU.BSUtil", "$.Escape", "$.Logger", "$.Point3fi"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.operations = null;
if (!Clazz.isClassDefined("JS.PointGroup.Operation")) {
JS.PointGroup.$PointGroup$Operation$ ();
}
if (!Clazz.isClassDefined("JS.PointGroup.Operator")) {
JS.PointGroup.$PointGroup$Operator$ ();
}
this.maxAtoms = 250;
this.maxElement = 0;
this.eCounts = null;
this.nOps = 0;
this.isAtoms = false;
this.iter = null;
this.drawType = "";
this.drawIndex = 0;
this.scale = NaN;
this.nAxes = null;
this.axes = null;
this.nAtoms = 0;
this.radius = 0;
this.distanceTolerance = 0.25;
this.distanceTolerance2 = 0;
this.linearTolerance = 8;
this.cosTolerance = 0.99;
this.name = "C_1?";
this.principalAxis = null;
this.principalPlane = null;
this.highOperations = null;
this.drawInfo = null;
this.info = null;
this.textInfo = null;
this.convention = 0;
this.vTemp = null;
this.centerAtomIndex = -1;
this.haveInversionCenter = false;
this.center = null;
this.points = null;
this.elements = null;
this.atomMap = null;
this.bsAtoms = null;
this.haveVibration = false;
this.localEnvOnly = false;
this.$isLinear = false;
this.sppa = 0;
this.isSpinGroup = false;
this.highestOrder = 0;
this.modelIndex = 0;
this.drawID = null;
this.type = null;
this.index = 0;
this.scaleFactor = 0;
Clazz.instantialize(this, arguments);}, JS, "PointGroup", null);
Clazz.prepareFields (c$, function(){
this.nAxes =  Clazz.newIntArray (JS.PointGroup.maxAxis, 0);
this.axes =  new Array(JS.PointGroup.maxAxis);
this.vTemp =  new JU.V3();
});
Clazz.makeConstructor(c$, 
function(isHM){
this.convention = (isHM ? 1 : 0);
}, "~B");
Clazz.defineMethod(c$, "newInversionCenter", 
function(index){
return Clazz.innerTypeInstance(JS.PointGroup.Operator, this, null, index, null, -1);
}, "~N");
Clazz.defineMethod(c$, "newPlane", 
function(index, v){
return Clazz.innerTypeInstance(JS.PointGroup.Operator, this, null, index, v, -1);
}, "~N,JU.V3");
Clazz.defineMethod(c$, "newAxis", 
function(index, v, arrayIndex){
return Clazz.innerTypeInstance(JS.PointGroup.Operator, this, null, index, v, arrayIndex);
}, "~N,JU.V3,~N");
c$.getUniqueFractions = Clazz.defineMethod(c$, "getUniqueFractions", 
function(order){
var bs = JS.PointGroup.bsUnique.get(Integer.$valueOf(order));
if (bs != null) return bs;
bs = JU.BSUtil.newBitSet2(1, order);
var n = Clazz.doubleToInt(order / 2);
for (var i = 1; i <= n; i++) {
var f = Clazz.doubleToInt(order / i);
if (f * i != order || !bs.get(f)) continue;
for (var j = f; j <= n; j += f) {
bs.clear(j);
bs.clear(order - j);
}
}
JS.PointGroup.bsUnique.put(Integer.$valueOf(order), bs);
return bs;
}, "~N");
c$.getPointGroup = Clazz.defineMethod(c$, "getPointGroup", 
function(pgLast, center, atomset, bsAtoms, haveVibration, distanceTolerance, linearTolerance, maxAtoms, localEnvOnly, isHM, sppa){
var pg =  new JS.PointGroup(isHM);
if (distanceTolerance <= 0) {
distanceTolerance = 0.01;
}if (linearTolerance <= 0) {
linearTolerance = 0.5;
}if (maxAtoms <= 0) maxAtoms = 250;
pg.distanceTolerance = distanceTolerance;
pg.distanceTolerance2 = distanceTolerance * distanceTolerance;
pg.linearTolerance = linearTolerance;
pg.maxAtoms = maxAtoms;
pg.isAtoms = (bsAtoms != null);
pg.bsAtoms = (pg.isAtoms ? bsAtoms : JU.BSUtil.newBitSet2(0, atomset.length));
pg.haveVibration = haveVibration;
pg.center = center;
pg.localEnvOnly = localEnvOnly;
pg.sppa = sppa;
if (JU.Logger.debugging) pgLast = null;
return (pg.set(pgLast, atomset) ? pg : pgLast);
}, "JS.PointGroup,JU.T3,~A,JU.BS,~B,~N,~N,~N,~B,~B,~N");
Clazz.defineMethod(c$, "set", 
function(pgLast, atomset){
this.cosTolerance = (Math.cos(this.linearTolerance / 180 * 3.141592653589793));
if (!this.getPointsAndElements(atomset)) {
JU.Logger.error("Too many atoms for point group calculation");
this.name = "point group not determined -- ac > " + this.maxAtoms + " -- select fewer atoms and try again.";
return true;
}this.getElementCounts();
var atomVibs =  new Array(this.points.length);
for (var i = 0; i < this.points.length; i++) {
atomVibs[i] = JU.P3.newP(this.points[i]);
var v = (this.points[i]).getVibrationVector();
if (v != null) {
if (v.isFrom000) {
this.isSpinGroup = true;
atomVibs = null;
this.haveVibration = true;
break;
} else if (!this.haveVibration) {
break;
}atomVibs[i].add(v);
}}
if (this.haveVibration && atomVibs != null) this.points = atomVibs;
if (this.isEqual(pgLast)) return false;
try {
this.findInversionCenter();
this.$isLinear = this.isLinear(this.points);
if (this.$isLinear) {
if (this.haveInversionCenter) {
this.name = "D\u221eh";
} else {
this.name = "C\u221ev";
}this.vTemp.sub2(this.points[1], this.points[0]);
this.addAxis(22, this.vTemp);
this.principalAxis = this.axes[22][0];
if (this.haveInversionCenter) {
this.axes[0] =  Clazz.newArray(-1, [this.principalPlane = this.newPlane(++this.nOps, this.vTemp)]);
this.nAxes[0] = 1;
}return true;
}this.axes[0] =  new Array(JS.PointGroup.axesMaxN[0]);
var nPlanes = 0;
this.findCAxes();
nPlanes = this.findPlanes();
this.findAdditionalAxes(nPlanes);
var n = this.getHighestOrder();
if (this.nAxes[23] > 1) {
if (this.nAxes[25] > 1) {
if (this.haveInversionCenter) {
this.name = "Ih";
} else {
this.name = "I";
}} else if (this.nAxes[24] > 1) {
if (this.haveInversionCenter) {
this.name = "Oh";
} else {
this.name = "O";
}} else {
if (nPlanes > 0) {
if (this.haveInversionCenter) {
this.name = "Th";
} else {
this.name = "Td";
}} else {
this.name = "T";
}}} else {
var n2 = this.nAxes[22];
if (n < 2) {
if (nPlanes == 1) {
this.name = "Cs";
return true;
}if (this.haveInversionCenter) {
this.name = "Ci";
return true;
}this.name = "C1";
} else if ((n % 2) == 1 && n2 > 0 || (n % 2) == 0 && n2 > 1) {
this.principalAxis = this.setPrincipalAxis(n, nPlanes);
if (nPlanes == 0) {
if (n < 20) {
this.name = "S" + n;
} else {
this.name = "D" + (n - 20);
}} else {
var arrayIndexTop = n;
if (n < 20) {
n = Clazz.doubleToInt(n / 2);
} else {
n -= 20;
}if (n2 > n + 1) {
this.addHighOperations(n2, nPlanes, arrayIndexTop, n);
if (this.principalPlane == null) {
n = nPlanes;
} else {
n = nPlanes - 1;
}} else {
}if (nPlanes == n) {
this.name = "D" + n + "d";
} else {
this.name = "D" + n + "h";
}}} else if (nPlanes == 0) {
this.principalAxis = this.axes[n][0];
if (n < 20) {
this.name = "S" + n;
} else {
this.name = "C" + (n - 20);
}} else {
if (nPlanes > 1) {
this.principalAxis = this.axes[n][0];
this.name = "C" + nPlanes + "v";
} else {
this.principalPlane = this.axes[0][0];
this.principalAxis = this.axes[n < 20 ? n + 20 : n][0];
if (n < 20) {
n /= 2;
} else {
n -= 20;
}this.name = "C" + n + "h";
}}}} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
this.name = "??";
} else {
throw e;
}
} finally {
JU.Logger.info("Point group found: " + this.name);
}
return true;
}, "JS.PointGroup,~A");
Clazz.defineMethod(c$, "addHighOperations", 
function(n2, nPlanes, arrayIndexTop, nTop){
var isS = (arrayIndexTop < 20);
if (isS) {
}}, "~N,~N,~N,~N");
Clazz.defineMethod(c$, "getPointsAndElements", 
function(atomset){
var ac = this.bsAtoms.cardinality();
if (this.isAtoms && ac > this.maxAtoms) return false;
this.points =  new Array(ac);
this.elements =  Clazz.newIntArray (ac, 0);
if (ac == 0) return true;
var atomIndexMax = 0;
for (var i = this.bsAtoms.nextSetBit(0); i >= 0; i = this.bsAtoms.nextSetBit(i + 1)) {
var p = atomset[i];
if (Clazz.instanceOf(p,"JU.Node")) atomIndexMax = Math.max(atomIndexMax, (p).i);
}
this.atomMap =  Clazz.newIntArray (atomIndexMax + 1, 0);
this.nAtoms = 0;
var needCenter = (this.center == null);
if (needCenter) this.center =  new JU.P3();
var bspt =  new J.bspt.Bspt(3, 0);
for (var i = this.bsAtoms.nextSetBit(0); i >= 0; i = this.bsAtoms.nextSetBit(i + 1), this.nAtoms++) {
var p = atomset[i];
if (Clazz.instanceOf(p,"JU.Node")) {
var bondIndex = (this.localEnvOnly ? 1 : 1 + Math.max(3, (p).getCovalentBondCount()));
this.elements[this.nAtoms] = (p).getElementNumber() * bondIndex;
this.atomMap[(p).i] = this.nAtoms + 1;
} else {
var newPt = JU.Point3fi.newPF(p, -1 - this.nAtoms);
if (Clazz.instanceOf(p,"JU.Point3fi")) this.elements[this.nAtoms] = Math.max(0, (p).sD);
p = newPt;
}bspt.addTuple(p);
if (needCenter) this.center.add(p);
this.points[this.nAtoms] = p;
}
this.iter = bspt.allocateCubeIterator();
if (needCenter) this.center.scale(1 / this.nAtoms);
for (var i = this.nAtoms; --i >= 0; ) {
var r2 = this.center.distanceSquared(this.points[i]);
if (this.isAtoms && r2 < this.distanceTolerance2) this.centerAtomIndex = i;
this.radius = Math.max(this.radius, r2);
}
this.radius = Math.sqrt(this.radius);
if (this.radius > 90) {
this.distanceTolerance = 0.3;
}if (this.radius > 90 || this.radius < 1.5 && this.distanceTolerance > 0.15) {
this.distanceTolerance = this.radius / 10;
this.distanceTolerance2 = this.distanceTolerance * this.distanceTolerance;
System.out.println("PointGroup calculation adjusting distanceTolerance to " + this.distanceTolerance);
}return true;
}, "~A");
Clazz.defineMethod(c$, "checkOperation", 
function(q, center, arrayIndex){
var pt =  new JU.P3();
var nFound = 0;
var isInversion = (arrayIndex < 20);
out : for (var n = this.points.length, i = n; --i >= 0 && nFound < n; ) {
if (i == this.centerAtomIndex) continue;
var a1 = this.points[i];
var e1 = this.elements[i];
if (q != null) {
pt.sub2(a1, center);
q.transform2(pt, pt).add(center);
} else {
pt.setT(a1);
}if (isInversion) {
this.vTemp.sub2(center, pt);
pt.scaleAdd2(2, this.vTemp, pt);
}if ((q != null || isInversion) && pt.distanceSquared(a1) < this.distanceTolerance2) {
nFound++;
continue;
}this.iter.initialize(pt, this.distanceTolerance, false);
while (this.iter.hasMoreElements()) {
var a2 = this.iter.nextElement();
if (a2 === a1) continue;
var j = this.getPointIndex((a2).i);
if (this.centerAtomIndex >= 0 && j == this.centerAtomIndex || j >= this.elements.length || this.elements[j] != e1) {
continue;
}if (pt.distanceSquared(a2) < this.distanceTolerance2) {
nFound++;
continue out;
}}
return false;
}
return true;
}, "JU.Quat,JU.T3,~N");
Clazz.defineMethod(c$, "findInversionCenter", 
function(){
this.haveInversionCenter = this.checkOperation(null, this.center, -1);
if (this.haveInversionCenter) {
this.axes[1] =  Clazz.newArray(-1, [this.newInversionCenter(++this.nOps)]);
this.nAxes[1] = 1;
}});
Clazz.defineMethod(c$, "setPrincipalAxis", 
function(n, nPlanes){
this.principalPlane = this.setPrincipalPlane(n, nPlanes);
if (nPlanes == 0 && n < 20 || this.nAxes[n] == 1) {
return this.axes[n][0];
}if (this.principalPlane == null) return null;
var c2axes = this.axes[22];
for (var i = 0; i < this.nAxes[22]; i++) if (this.isParallel(this.principalPlane.normalOrAxis, c2axes[i].normalOrAxis)) {
if (i != 0) {
var o = c2axes[0];
c2axes[0] = c2axes[i];
c2axes[i] = o;
}return c2axes[0];
}
return null;
}, "~N,~N");
Clazz.defineMethod(c$, "setPrincipalPlane", 
function(n, nPlanes){
var planes = this.axes[0];
if (nPlanes == 1) return this.principalPlane = planes[0];
if (nPlanes == 0 || nPlanes == n - 20) return null;
for (var i = 0; i < nPlanes; i++) {
for (var j = 0, nPerp = 0; j < nPlanes; j++) {
if (this.isPerpendicular(planes[i].normalOrAxis, planes[j].normalOrAxis) && ++nPerp > 2) {
if (i != 0) {
var o = planes[0];
planes[0] = planes[i];
planes[i] = o;
}return this.principalPlane = planes[0];
}}
}
return null;
}, "~N,~N");
Clazz.defineMethod(c$, "getPointIndex", 
function(j){
return (j < 0 ? -j : this.atomMap[j]) - 1;
}, "~N");
Clazz.defineMethod(c$, "getElementCounts", 
function(){
for (var i = this.points.length; --i >= 0; ) {
var e1 = this.elements[i];
if (e1 > this.maxElement) this.maxElement = e1;
}
this.eCounts =  Clazz.newIntArray (++this.maxElement, 0);
for (var i = this.points.length; --i >= 0; ) this.eCounts[this.elements[i]]++;

});
Clazz.defineMethod(c$, "findCAxes", 
function(){
var v1 =  new JU.V3();
var v2 =  new JU.V3();
var v3 =  new JU.V3();
for (var i = this.points.length; --i >= 0; ) {
if (i == this.centerAtomIndex) continue;
var a1 = this.points[i];
var e1 = this.elements[i];
for (var j = this.points.length; --j > i; ) {
var a2 = this.points[j];
if (this.elements[j] != e1) continue;
v1.sub2(a1, this.center);
v2.sub2(a2, this.center);
v1.normalize();
v2.normalize();
if (this.isParallel(v1, v2)) {
this.getAllAxes(v1);
continue;
}if (this.nAxes[22] < JS.PointGroup.axesMaxN[22]) {
v3.ave(a1, a2);
v3.sub(this.center);
this.getAllAxes(v3);
}var order = (6.283185307179586 / v1.angle(v2));
var iOrder = Clazz.doubleToInt(Math.floor(order + 0.01));
var isIntegerOrder = (order - iOrder <= 0.02);
var arrayIndex = iOrder + 20;
if (!isIntegerOrder || (arrayIndex) >= JS.PointGroup.maxAxis) continue;
if (this.nAxes[arrayIndex] < JS.PointGroup.axesMaxN[arrayIndex]) {
v3.cross(v1, v2);
this.checkForAxis(arrayIndex, v3);
}}
}
var vs =  new Array(this.nAxes[22] * 2);
for (var i = 0; i < vs.length; i++) vs[i] =  new JU.V3();

var n = 0;
for (var i = 0; i < this.nAxes[22]; i++) {
vs[n++].setT(this.axes[22][i].normalOrAxis);
vs[n].setT(this.axes[22][i].normalOrAxis);
vs[n++].scale(-1);
}
for (var i = vs.length; --i >= 2; ) for (var j = i; --j >= 1; ) for (var k = j; --k >= 0; ) {
v3.add2(vs[i], vs[j]);
v3.add(vs[k]);
if (v3.length() < 1) continue;
this.checkForAxis(23, v3);
}


var nMin = 2147483647;
var iMin = -1;
for (var i = 0; i < this.maxElement; i++) {
if (this.eCounts[i] < nMin && this.eCounts[i] > 2) {
nMin = this.eCounts[i];
iMin = i;
}}
out : for (var i = 0; i < this.points.length - 2; i++) if (this.elements[i] == iMin) for (var j = i + 1; j < this.points.length - 1; j++) if (this.elements[j] == iMin) for (var k = j + 1; k < this.points.length; k++) if (this.elements[k] == iMin) {
v1.sub2(this.points[i], this.points[j]);
v2.sub2(this.points[i], this.points[k]);
v1.normalize();
v2.normalize();
v3.cross(v1, v2);
this.getAllAxes(v3);
v1.add2(this.points[i], this.points[j]);
v1.add(this.points[k]);
v1.normalize();
if (!this.isParallel(v1, v3)) this.getAllAxes(v1);
if (this.nAxes[25] == JS.PointGroup.axesMaxN[25]) break out;
}


vs =  new Array(this.maxElement);
for (var i = this.points.length; --i >= 0; ) {
var e1 = this.elements[i];
if (vs[e1] == null) vs[e1] =  new JU.V3();
 else if (this.haveInversionCenter) continue;
vs[e1].add(this.points[i]);
}
if (!this.haveInversionCenter) for (var i = 0; i < this.maxElement; i++) if (vs[i] != null) vs[i].scale(1 / this.eCounts[i]);

for (var i = 0; i < this.maxElement; i++) if (vs[i] != null) for (var j = 0; j < this.maxElement; j++) {
if (i == j || vs[j] == null) continue;
if (this.haveInversionCenter) v1.cross(vs[i], vs[j]);
 else v1.sub2(vs[i], vs[j]);
this.checkForAxis(22, v1);
}

return this.getHighestOrder();
});
Clazz.defineMethod(c$, "getAllAxes", 
function(v3){
for (var o = 22; o < JS.PointGroup.maxAxis; o++) if (this.nAxes[o] < JS.PointGroup.axesMaxN[o]) {
this.checkForAxis(o, v3);
}
}, "JU.V3");
Clazz.defineMethod(c$, "getHighestOrder", 
function(){
var n = 0;
for (n = 20; --n > 1 && this.nAxes[n] == 0; ) {
}
if (n > 1) return (n + 20 < JS.PointGroup.maxAxis && this.nAxes[n + 20] > 0 ? n + 20 : n);
for (n = JS.PointGroup.maxAxis; --n > 1 && this.nAxes[n] == 0; ) {
}
return n;
});
Clazz.defineMethod(c$, "checkForAxis", 
function(arrayIndex, v){
if (!this.isCompatible(arrayIndex)) return false;
v.normalize();
if (this.haveAxis(arrayIndex, v)) return false;
var q = JS.PointGroup.getQuaternion(v, arrayIndex);
if (!this.checkOperation(q, this.center, arrayIndex)) return false;
this.addAxis(arrayIndex, v);
this.checkForAssociatedAxes(arrayIndex, v);
return true;
}, "~N,JU.V3");
Clazz.defineMethod(c$, "checkForAssociatedAxes", 
function(arrayIndex, v){
switch (arrayIndex) {
case 22:
this.checkForAxis(4, v);
break;
case 23:
this.checkForAxis(3, v);
if (this.haveInversionCenter) this.addAxis(6, v);
break;
case 24:
this.addAxis(22, v);
this.checkForAxis(4, v);
this.checkForAxis(8, v);
break;
case 25:
this.checkForAxis(5, v);
if (this.haveInversionCenter) this.addAxis(10, v);
break;
case 26:
this.addAxis(22, v);
this.addAxis(23, v);
this.checkForAxis(3, v);
this.checkForAxis(6, v);
this.checkForAxis(12, v);
break;
case 27:
this.checkForAxis(6, v);
if (this.haveInversionCenter) this.addAxis(14, v);
break;
case 28:
this.addAxis(22, v);
this.addAxis(24, v);
this.checkForAxis(4, v);
this.checkForAxis(8, v);
this.checkForAxis(16, v);
break;
}
}, "~N,JU.V3");
Clazz.defineMethod(c$, "isCompatible", 
function(arrayIndex){
switch (arrayIndex) {
case 28:
if (this.nAxes[7] > 0 || this.nAxes[23] > 0) return false;
case 26:
case 24:
if (this.nAxes[27] > 0 || this.nAxes[25] > 0) return false;
break;
case 22:
break;
case 23:
if (this.nAxes[27] > 0 || this.nAxes[28] > 0) return false;
break;
case 25:
if (this.nAxes[24] > 0 || this.nAxes[26] > 0 || this.nAxes[27] > 0 || this.nAxes[28] > 0) return false;
break;
case 27:
if (this.nAxes[23] > 0 || this.nAxes[24] > 0 || this.nAxes[25] > 0 || this.nAxes[26] > 0 || this.nAxes[28] > 0) return false;
break;
}
return true;
}, "~N");
Clazz.defineMethod(c$, "haveAxis", 
function(arrayIndex, v){
if (this.nAxes[arrayIndex] == JS.PointGroup.axesMaxN[arrayIndex]) {
return true;
}if (this.nAxes[arrayIndex] > 0) for (var i = this.nAxes[arrayIndex]; --i >= 0; ) {
if (this.isParallel(v, this.axes[arrayIndex][i].normalOrAxis)) return true;
}
return false;
}, "~N,JU.V3");
Clazz.defineMethod(c$, "addAxis", 
function(arrayIndex, v){
if (this.haveAxis(arrayIndex, v)) return;
if (this.axes[arrayIndex] == null) this.axes[arrayIndex] =  new Array(JS.PointGroup.axesMaxN[arrayIndex]);
this.axes[arrayIndex][this.nAxes[arrayIndex]++] = this.newAxis(++this.nOps, v, arrayIndex);
}, "~N,JU.V3");
Clazz.defineMethod(c$, "findPlanes", 
function(){
var pt =  new JU.P3();
var v1 =  new JU.V3();
var v2 =  new JU.V3();
var v3 =  new JU.V3();
var nPlanes = 0;
var haveAxes = (this.getHighestOrder() > 1);
for (var i = this.points.length; --i >= 0; ) {
if (i == this.centerAtomIndex) continue;
var a1 = this.points[i];
var e1 = this.elements[i];
for (var j = this.points.length; --j > i; ) {
if (haveAxes && this.elements[j] != e1) continue;
var a2 = this.points[j];
pt.add2(a1, a2);
pt.scale(0.5);
v1.sub2(a1, this.center);
v2.sub2(a2, this.center);
v1.normalize();
v2.normalize();
if (!this.isParallel(v1, v2)) {
v3.cross(v1, v2);
v3.normalize();
nPlanes = this.addPlane(v3);
}v3.sub2(a2, a1);
v3.normalize();
nPlanes = this.addPlane(v3);
if (nPlanes == JS.PointGroup.axesMaxN[0]) return nPlanes;
}
}
if (haveAxes) for (var i = 22; i < JS.PointGroup.maxAxis; i++) for (var j = 0; j < this.nAxes[i]; j++) nPlanes = this.addPlane(this.axes[i][j].normalOrAxis);


return nPlanes;
});
Clazz.defineMethod(c$, "findAdditionalAxes", 
function(nPlanes){
var planes = this.axes[0];
var Cn = 0;
if (nPlanes > 1 && ((Cn = nPlanes + 20) < JS.PointGroup.maxAxis) && this.nAxes[Cn] == 0) {
this.vTemp.cross(planes[0].normalOrAxis, planes[1].normalOrAxis);
if (!this.checkForAxis(Cn, this.vTemp) && nPlanes > 2) {
this.vTemp.cross(planes[1].normalOrAxis, planes[2].normalOrAxis);
this.checkForAxis(Cn - 1, this.vTemp);
}}if (this.nAxes[22] == 0 && nPlanes > 2) {
for (var i = 0; i < nPlanes - 1; i++) {
for (var j = i + 1; j < nPlanes; j++) {
this.vTemp.add2(planes[1].normalOrAxis, planes[2].normalOrAxis);
this.checkForAxis(22, this.vTemp);
}
}
}}, "~N");
Clazz.defineMethod(c$, "addPlane", 
function(v3){
if (!this.haveAxis(0, v3) && this.checkOperation(JU.Quat.newVA(v3, 180), this.center, -1)) this.axes[0][this.nAxes[0]++] = this.newPlane(++this.nOps, v3);
return this.nAxes[0];
}, "JU.V3");
Clazz.defineMethod(c$, "getName", 
function(){
return this.getNameByConvention(this.name);
});
Clazz.defineMethod(c$, "updateDraw", 
function(){
return null;
});
Clazz.defineMethod(c$, "getInfo", 
function(modelIndex, a1, a2, drawID, asMap, type, index, scaleFactor){
if (drawID == null && type == null && !asMap && this.textInfo != null) return this.textInfo;
if (drawID != null) {
this.modelIndex = modelIndex;
this.drawID = drawID;
this.type = type;
this.index = index;
this.scaleFactor = scaleFactor;
}this.operations =  new JU.Lst();
var justThisUVW = (type != null && type.indexOf("u") >= 0);
if (a1 == null && a2 == null && drawID == null && this.drawInfo != null && this.drawIndex == index && this.scale == this.scale && this.drawType.equals(type == null ? "" : type)) return this.drawInfo;
if (asMap && this.info != null) return this.info;
var asDraw = (drawID != null);
this.info = null;
var elements = null;
var v =  new JU.V3();
var op;
if (scaleFactor == 0) scaleFactor = 1;
this.scale = scaleFactor;
var nType =  Clazz.newIntArray (4, 2, 0);
for (var i = 1; i < JS.PointGroup.maxAxis; i++) for (var j = this.nAxes[i]; --j >= 0; ) nType[this.axes[i][j].type][0]++;


var sb =  new JU.SB().append("# ").appendI(this.nAtoms).append(" atoms\n");
var name = this.getNameByConvention(this.name);
var haveThisType = false;
var operationInv = (this.haveInversionCenter ? Clazz.innerTypeInstance(JS.PointGroup.Operation, this, null, null) : null);
if (operationInv != null) {
this.operations.addLast(operationInv);
}if (asDraw) {
var haveType = (type != null && type.length > 0);
this.drawType = type = (haveType ? type : "");
this.drawIndex = index;
var anyProperAxis = (type.equalsIgnoreCase(this.getNameByConvention("Cn")));
var anyImproperAxis = (type.equalsIgnoreCase(this.getNameByConvention("Sn")));
var head = "set perspectivedepth off;draw " + drawID + " delete;\n";
var m = "_" + modelIndex + "_";
if (justThisUVW || !haveType) sb.append(this.getDrawID("pg0" + m + "* delete;") + this.getDrawID("pgva" + m + "* delete;") + this.getDrawID("pgvp" + m + "* delete;"));
if (!haveType || type.equalsIgnoreCase("Ci") || type.equalsIgnoreCase("-u,-v,-w")) {
var pt = sb.length();
sb.append(this.getDrawID("pg0" + m + (this.haveInversionCenter ? "inv" : "")));
if (operationInv != null) {
operationInv.drawID = sb.substring(pt);
haveThisType = justThisUVW;
}sb.append(" ").append(JU.Escape.eP(this.center)).append(this.haveInversionCenter ? "\"i\";\n" : ";\n");
if (operationInv != null) {
operationInv.drawStr = sb.substring(pt);
System.out.println(operationInv);
}}var offset = 0.1;
var axisWidth = (this.isSpinGroup ? 1.9 : 0.05);
for (var i = 2; i < JS.PointGroup.maxAxis && !haveThisType; i++) {
if (i == 20) offset = 0.1;
if (this.nAxes[i] == 0) continue;
var sglabel = (this.$isLinear ? "C\u221e" : this.getOpName(this.axes[i][0], false));
var label = (this.$isLinear ? "\u221e" : this.getOpName(this.axes[i][0], true));
offset += 0.25;
var scale = scaleFactor * 1.05 * this.radius + offset * 80 / this.sppa;
var isProper = (i >= 20);
this.highestOrder = this.getHighestOrder();
if (justThisUVW || !haveType || type.equalsIgnoreCase(label) || anyProperAxis && isProper || anyImproperAxis && !isProper) {
for (var j = 0; j < this.nAxes[i]; j++) {
if (index > 0 && j + 1 != index) continue;
op = this.axes[i][j];
if (justThisUVW && !op.isUVW(type)) continue;
haveThisType = justThisUVW;
this.operations.addLast(op.operation = Clazz.innerTypeInstance(JS.PointGroup.Operation, this, null, op));
var pt = sb.length();
var s = this.getDrawID("pgva" + m + sglabel.$replace("\u221e", "infinity") + (j + 1) + "\1");
op.operation.drawID = sb.substring(pt).$replace('\1', ' ');
this.drawAxis(sb, op, s, label, axisWidth, scale, isProper, 'a', v);
this.drawAxis(sb, op, s, label, axisWidth, scale, isProper, 'b', v);
op.operation.drawStr = sb.substring(pt);
if (JU.Logger.debugging) JU.Logger.debug(op.operation.toString());
}
}}
if (justThisUVW || !haveType || type.equalsIgnoreCase(this.getNameByConvention("Cs"))) {
for (var j = 0; j < this.nAxes[0] && !haveThisType; j++) {
if (index > 0 && j + 1 != index) continue;
op = this.axes[0][j];
if (justThisUVW && !op.isUVW(type)) continue;
haveThisType = justThisUVW;
this.operations.addLast(op.operation = Clazz.innerTypeInstance(JS.PointGroup.Operation, this, null, op));
var s = op.operation.drawID = this.getDrawID("pgvp" + m + (j + 1) + "\1");
var pt = sb.length();
this.drawPlane(sb, op, s, scaleFactor, v);
op.operation.drawStr = sb.substring(pt);
if (JU.Logger.debugging) JU.Logger.debug(op.operation.toString());
}
if (justThisUVW && a1 != null && a2 != null) {
var cmd = this.getDrawID("pg0" + m + "_line");
sb.append(cmd).append(" width " + axisWidth / 2 + " " + a1 + a2 + " color yellow;");
}}sb.append("# name=").append(name);
sb.append(", n" + this.getNameByConvention("Ci") + "=").appendI(this.haveInversionCenter ? 1 : 0);
sb.append(", n" + this.getNameByConvention("Cs") + "=").appendI(this.nAxes[0]);
sb.append(", n" + this.getNameByConvention("Cn") + "=").appendI(nType[1][0]);
sb.append(", n" + this.getNameByConvention("Sn") + "=").appendI(nType[2][0]);
sb.append(": ");
for (var i = JS.PointGroup.maxAxis; --i >= 2; ) {
if (this.nAxes[i] > 0) {
var axisName = this.getNameByConvention((i < 20 ? "S" : "C") + (i % 20));
sb.append(" n").append(axisName);
sb.append("=").appendI(this.nAxes[i]);
}}
sb.append(";\n");
sb.append("print '" + name + "';\n");
this.drawInfo = head + sb.toString();
if (JU.Logger.debugging) JU.Logger.info(this.drawInfo);
return this.drawInfo;
}var n = 0;
var nTotal = 1;
var nElements = 0;
var ctype = (this.haveInversionCenter ? this.getNameByConvention("Ci") : "center");
if (this.haveInversionCenter) {
nTotal++;
nElements++;
}if (asMap) {
this.info =  new java.util.Hashtable();
elements =  new JU.Lst();
if (this.center != null) {
this.info.put(ctype, this.center);
if (this.haveInversionCenter) this.info.put("center", this.center);
this.info.put(ctype, this.center);
}this.info.put("elements", elements);
if (this.haveInversionCenter) {
this.info.put("Ci_m", JU.M3.newM3(JS.PointGroup.mInv));
var e =  new java.util.Hashtable();
this.axes[1][0].setInfo(null, null, e);
e.put("location", this.center);
e.put("type", this.getNameByConvention("Ci"));
elements.addLast(e);
}} else {
sb.append("\n\n").append(name).append("\t").append(ctype).append("\t").append(JU.Escape.eP(this.center));
}for (var i = JS.PointGroup.maxAxis; --i >= 0; ) {
if (i == 1 || this.nAxes[i] == 0) continue;
n = JS.PointGroup.nUnique[i];
var a = this.axes[i];
var sglabel = this.getOpName(a[0], false);
var label = this.getOpName(a[0], true);
var ni = this.nAxes[i];
if (asMap) {
this.info.put("n" + sglabel, Integer.$valueOf(this.nAxes[i]));
} else {
sb.append("\n\n").append(name).append("\tn").append(label).append("\t").appendI(ni).append("\t").appendI(n);
}n *= ni;
nTotal += n;
nElements += ni;
nType[a[0].type][1] += n;
var vinfo = (asMap ?  new JU.Lst() : null);
var minfo = (asMap ?  new JU.Lst() : null);
for (var j = 0; j < ni; j++) {
var aop = a[j];
if (type != null && !aop.isUVW(type)) continue;
if (!asDraw && aop.operation == null) this.operations.addLast(aop.operation = Clazz.innerTypeInstance(JS.PointGroup.Operation, this, null, aop));
if (asMap) {
var e =  new java.util.Hashtable();
aop.setInfo(vinfo, minfo, e);
e.put("type", label);
elements.addLast(e);
} else {
sb.append("\n").append(name).append("\t").append(sglabel).append("_").appendI(j + 1).append("\t").appendO(aop.normalOrAxis);
}}
if (asMap) {
this.info.put(sglabel, vinfo);
this.info.put(sglabel + "_m", minfo);
}}
if (asMap && this.highOperations != null) {
for (var o, $o = this.highOperations.iterator (); $o.hasNext()&& ((o = $o.next ()) || true);) {
var e =  new java.util.Hashtable();
o.setInfo(null, null, e);
e.put("type", this.getNameByConvention(o.schName));
elements.addLast(e);
}
}if (!asMap) return this.textInfo = this.getTextInfo(sb, nType, nTotal);
this.info.put("name", this.name);
this.info.put("hmName", this.getHermannMauguinName());
this.info.put("nAtoms", Integer.$valueOf(this.nAtoms));
this.info.put("nTotal", Integer.$valueOf(nTotal));
this.info.put("nElements", Integer.$valueOf(nElements));
this.info.put("nCi", Integer.$valueOf(this.nAxes[1]));
this.info.put("nC2", Integer.$valueOf(this.nAxes[22]));
this.info.put("nC3", Integer.$valueOf(this.nAxes[23]));
this.info.put("nCs", Integer.$valueOf(this.nAxes[0]));
this.info.put("nCn", Integer.$valueOf(nType[1][0]));
this.info.put("nSn", Integer.$valueOf(nType[2][0]));
this.info.put("distanceTolerance", Float.$valueOf(this.distanceTolerance));
this.info.put("linearTolerance", Float.$valueOf(this.linearTolerance));
this.info.put("points", this.points);
this.info.put("detail", sb.toString().$replace('\n', ';'));
if (this.principalAxis != null && this.principalAxis.index > 0) this.info.put("principalAxis", this.principalAxis.normalOrAxis);
if (this.principalPlane != null && this.principalPlane.index > 0) this.info.put("principalPlane", this.principalPlane.normalOrAxis);
return this.info;
}, "~N,JU.T3,JU.T3,~S,~B,~S,~N,~N");
Clazz.defineMethod(c$, "getDrawID", 
function(id){
var pt = id.indexOf(' ');
if (pt < 0) pt = id.length;
id = id.substring(0, pt) + "\"" + id.substring(pt);
return "draw" + (id.indexOf("*") >= 0 ? "" : " model " + this.modelIndex) + " ID \"" + id;
}, "~S");
Clazz.defineMethod(c$, "drawPlane", 
function(sb, op, s, scaleFactor, v){
sb.append(s.$replace("\1", "disk"));
sb.append(" scale ").appendD(scaleFactor * this.radius * 2).append(" CIRCLE PLANE ").append(JU.Escape.eP(this.center));
v.add2(op.normalOrAxis, this.center);
sb.append(JU.Escape.eP(v)).append(" color translucent yellow;\n");
v.add2(op.normalOrAxis, this.center);
sb.append(s.$replace("\1", "ring"));
sb.append(" width 0.05 scale ").appendD(scaleFactor * this.radius * 2).append(" arc ").append(JU.Escape.eP(v));
v.scaleAdd2(-2, op.normalOrAxis, v);
sb.append(JU.Escape.eP(v));
v.add3(0.011, 0.012, 0.013);
sb.append(JU.Escape.eP(v)).append("{0 360 0.5} color ").append(this.principalPlane != null && op.index == this.principalPlane.index ? "red" : "blue").append(";\n");
}, "JU.SB,JS.PointGroup.Operator,~S,~N,JU.V3");
Clazz.defineMethod(c$, "drawAxis", 
function(sb, op, s, label, axisWidth, scale, isProper, c, v){
var isPA = (!this.$isLinear && this.principalAxis != null && op.index == this.principalAxis.index);
var pt = sb.length();
sb.append(" color ").append(isPA ? "red" : op.type == 2 ? "blue" : "orange").append(";\n");
var tail = sb.substring(pt);
sb.setLength(pt);
sb.append(s.$replace('\1', c));
v.scaleAdd2((c == 'a' ? 1 : -1) * scale, op.normalOrAxis, this.center);
sb.append(" width " + axisWidth).append(" ").append(JU.Escape.eP(this.center)).append(JU.Escape.eP(v));
if (isProper && op.order != 2 && c == 'a' || op.order == 2 || !isProper && c == 'b') {
label = JU.PT.esc(">" + this.getAxisLabelOffset(op, this.highestOrder) + label);
sb.append(label);
}sb.append(tail);
}, "JU.SB,JS.PointGroup.Operator,~S,~S,~N,~N,~B,~S,JU.V3");
Clazz.defineMethod(c$, "getAxisLabelOffset", 
function(op, highestOrder){
switch (op.type) {
case 2:
case 1:
switch (op.order) {
case 3:
return (highestOrder % 6 == 0 ? "   " : "");
case 4:
return (highestOrder % 12 == 0 ? "       " : "   ");
case 6:
return (highestOrder % 12 == 0 ? "           " : "");
case 8:
return "       ";
case 12:
return "               ";
default:
return "";
}
default:
case 0:
case 3:
return "";
}
}, "JS.PointGroup.Operator,~N");
Clazz.defineMethod(c$, "getTextInfo", 
function(sb, nType, nTotal){
sb.append("\n");
sb.append("\n").append(this.name).append("\ttype\tnElements\tnUnique");
sb.append("\n").append(this.name).append("\t" + this.getNameByConvention("E") + "\t  1\t  1");
sb.append("\n").append(this.name).append("\t" + this.getNameByConvention("Ci") + "\t  ").appendI(this.nAxes[1]).append("\t  ").appendI(this.nAxes[1]);
sb.append("\n").append(this.name).append("\t" + this.getNameByConvention("Cs") + "\t");
JU.PT.rightJustify(sb, "    ", this.nAxes[0] + "\t");
JU.PT.rightJustify(sb, "    ", this.nAxes[0] + "\n");
sb.append(this.name).append("\t" + this.getNameByConvention("Cn") + "\t");
JU.PT.rightJustify(sb, "    ", nType[1][0] + "\t");
JU.PT.rightJustify(sb, "    ", nType[1][1] + "\n");
sb.append(this.name).append("\t" + this.getNameByConvention("Sn") + "\t");
JU.PT.rightJustify(sb, "    ", nType[2][0] + "\t");
JU.PT.rightJustify(sb, "    ", nType[2][1] + "\n");
sb.append(this.name).append("\t\tTOTAL\t");
JU.PT.rightJustify(sb, "    ", nTotal + "\n");
return sb.toString();
}, "JU.SB,~A,~N");
c$.getQuaternion = Clazz.defineMethod(c$, "getQuaternion", 
function(v, arrayIndex){
return JU.Quat.newVA(v, (arrayIndex < 20 ? 180 : 0) + (arrayIndex == 0 ? 0 : Clazz.doubleToInt(360 / (arrayIndex % 20))));
}, "JU.V3,~N");
Clazz.defineMethod(c$, "isLinear", 
function(atoms){
var v1 = null;
if (atoms.length < 2) return false;
for (var i = atoms.length; --i >= 0; ) {
if (i == this.centerAtomIndex) continue;
if (v1 == null) {
v1 =  new JU.V3();
v1.sub2(atoms[i], this.center);
v1.normalize();
this.vTemp.setT(v1);
continue;
}this.vTemp.sub2(atoms[i], this.center);
this.vTemp.normalize();
if (!this.isParallel(v1, this.vTemp)) return false;
}
return true;
}, "~A");
Clazz.defineMethod(c$, "isParallel", 
function(v1, v2){
return (Math.abs(v1.dot(v2)) >= this.cosTolerance);
}, "JU.V3,JU.V3");
Clazz.defineMethod(c$, "isPerpendicular", 
function(v1, v2){
return (Math.abs(v1.dot(v2)) <= 1 - this.cosTolerance);
}, "JU.V3,JU.V3");
Clazz.defineMethod(c$, "isEqual", 
function(pg){
if (pg == null) return false;
if (this.convention != pg.convention || this.linearTolerance != pg.linearTolerance || this.distanceTolerance != pg.distanceTolerance || this.nAtoms != pg.nAtoms || this.localEnvOnly != pg.localEnvOnly || this.haveVibration != pg.haveVibration || this.bsAtoms == null ? pg.bsAtoms != null : !this.bsAtoms.equals(pg.bsAtoms)) return false;
for (var i = 0; i < this.nAtoms; i++) {
if (this.elements[i] != pg.elements[i] || !this.points[i].equals(pg.points[i])) return false;
}
return true;
}, "JS.PointGroup");
Clazz.defineMethod(c$, "getHermannMauguinName", 
function(){
return JS.PointGroup.getHMfromSFName(this.name);
});
Clazz.defineMethod(c$, "getNameByConvention", 
function(name){
switch (this.convention) {
default:
case 0:
return name;
case 1:
return JS.PointGroup.getHMfromSFName(name);
}
}, "~S");
Clazz.defineMethod(c$, "getOpName", 
function(op, conventional){
return (conventional ? this.getNameByConvention(op.schName) : op.schName);
}, "JS.PointGroup.Operator,~B");
c$.getHMfromSFName = Clazz.defineMethod(c$, "getHMfromSFName", 
function(name){
if (JS.PointGroup.htSFToHM == null) {
JS.PointGroup.htSFToHM =  new java.util.Hashtable();
var syms = JS.PointGroup.SF2HM;
JS.PointGroup.addNames("E", "1");
JS.PointGroup.addNames("Ci", "-1");
JS.PointGroup.addNames("Cn", "n");
JS.PointGroup.addNames("Sn", "-n");
for (var i = 0; i < syms.length; i++) {
var list = syms[i].$plit(",");
var sym = list[0];
if (list.length == 2) {
JS.PointGroup.addNames(sym, list[1]);
continue;
}var type = sym.substring(0, 1);
var ext = sym.substring(2, sym.length);
for (var n = 1; n < 13; n++) {
var val = list[n];
if (val.length > 0) {
JS.PointGroup.addNames(type + n + ext, val);
if (JU.Logger.debugging) JU.Logger.debug(type + n + ext + "\t" + val);
}}
if (list.length == 14) {
JS.PointGroup.addNames(type + "\u221e" + ext, list[13]);
}}
}var hm = JS.PointGroup.htSFToHM.get(name);
return (hm == null ? name : hm);
}, "~S");
c$.addNames = Clazz.defineMethod(c$, "addNames", 
function(sch, hm){
JS.PointGroup.htSFToHM.put(sch, hm);
JS.PointGroup.htSFToHM.put(hm, sch);
}, "~S,~S");
c$.$PointGroup$Operation$ = function(){
/*if4*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.operator = null;
this.drawID = null;
this.drawStr = null;
this.isTrivial = false;
this.typeName = null;
this.type = 0;
Clazz.instantialize(this, arguments);}, JS.PointGroup, "Operation", null);
Clazz.makeConstructor(c$, 
function(o){
this.operator = o;
this.type = (o == null ? 3 : o.type);
this.typeName = (o == null ? "Ci" : o.schName);
this.isTrivial = this.checkTrivial();
}, "JS.PointGroup.Operator");
Clazz.defineMethod(c$, "checkTrivial", 
function(){
var tol = this.b$["JS.PointGroup"].distanceTolerance;
for (var i = this.b$["JS.PointGroup"].points.length; --i >= 0; ) {
var d;
switch (this.type) {
case 0:
case 2:
case 1:
d = this.operator.distance(this.b$["JS.PointGroup"].points[i]);
break;
default:
case 3:
d = this.b$["JS.PointGroup"].points[i].length();
break;
}
if (d > tol) {
return false;
}}
System.out.println(this + " is trivial");
return true;
});
Clazz.overrideMethod(c$, "toString", 
function(){
return "[op " + this.typeName + " " + this.drawID + " " + this.isTrivial + "]";
});
/*eoif4*/})();
};
c$.$PointGroup$Operator$ = function(){
/*if4*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.type = 0;
this.index = 0;
this.normalOrAxis = null;
this.mat = null;
this.order = 0;
this.axisArrayIndex = 0;
this.schName = null;
this.uvws = null;
this.uniqueMats = null;
this.mats = null;
this.operation = null;
this.uniqueUVWs = null;
Clazz.instantialize(this, arguments);}, JS.PointGroup, "Operator", null);
Clazz.makeConstructor(c$, 
function(index, v, arrayIndex){
this.index = index;
if (v == null) {
this.type = 3;
this.axisArrayIndex = 1;
this.order = 2;
this.schName = "Ci";
this.mat = JS.PointGroup.mInv;
} else {
this.normalOrAxis = JU.Quat.newVA(v, 180).getNormal();
if (arrayIndex == -1) {
this.type = 0;
this.axisArrayIndex = 0;
this.order = 2;
this.schName = "Cs";
} else {
this.type = (arrayIndex < 20 ? 2 : 1);
this.axisArrayIndex = arrayIndex;
this.order = arrayIndex % 20;
this.schName = (this.type == 2 ? "S" : "C") + this.order;
}}if (JU.Logger.debugging) JU.Logger.debug("new operation -- " + index + " " + this.schName + (this.normalOrAxis == null ? "" : " " + this.normalOrAxis));
}, "~N,JU.V3,~N");
Clazz.defineMethod(c$, "getM3", 
function(){
if (this.mat != null) return this.mat;
var m = JU.M3.newM3(JS.PointGroup.getQuaternion(this.normalOrAxis, this.axisArrayIndex).getMatrix());
if (this.type == 0 || this.type == 2) m.mul(JS.PointGroup.mInv);
this.cleanMatrix(m);
return this.mat = m;
});
Clazz.defineMethod(c$, "distance", 
function(pt){
var p = JU.P3.newP(pt);
this.getM3().rotate(p);
return p.distance(pt);
}, "JU.T3");
Clazz.defineMethod(c$, "cleanMatrix", 
function(m){
for (var i = 0; i < 3; i++) for (var j = 0; j < 3; j++) m.setElement(i, j, this.approx0(m.getElement(i, j)));


}, "JU.M3");
Clazz.defineMethod(c$, "approx0", 
function(v){
return (v > 1e-15 || v < -1.0E-15 ? v : 0);
}, "~N");
Clazz.defineMethod(c$, "getMatrices", 
function(uniqueOnly){
if (uniqueOnly && this.uniqueMats != null) return this.uniqueMats;
if (!uniqueOnly && this.mats != null) return this.mats;
var matrices =  new JU.Lst();
var m =  new JU.M3();
m.m00 = m.m11 = m.m22 = 1;
var bs = (uniqueOnly ? JS.PointGroup.getUniqueFractions(this.order) : null);
for (var i = 1; i < this.order; i++) {
m.mul(this.getM3());
if (!uniqueOnly || bs.get(i)) matrices.addLast(JU.M3.newM3(m));
}
if (uniqueOnly) this.uniqueMats = matrices;
 else this.mats = matrices;
return matrices;
}, "~B");
Clazz.overrideMethod(c$, "toString", 
function(){
return this.schName + " " + this.normalOrAxis;
});
Clazz.defineMethod(c$, "setInfo", 
function(vinfo, minfo, e){
if (vinfo != null) {
vinfo.addLast(this.normalOrAxis);
minfo.addLast(this.getM3());
}e.put("order", Integer.$valueOf(this.order));
e.put("typeSch", this.schName);
e.put("typeHM", JS.PointGroup.getHMfromSFName(this.schName));
if (this.normalOrAxis != null) e.put("direction", this.normalOrAxis);
var mats = this.getMatrices(true);
e.put("uniqueOperations", mats);
e.put("uniqueOperationUVWs", this.getUVWs(mats, true));
if (mats.size() != this.order - 1) e.put("matrixIndices", JS.PointGroup.bsUnique.get(Integer.$valueOf(this.order)));
mats = this.getMatrices(false);
e.put("operations", mats);
e.put("operationUVWs", this.getUVWs(mats, false));
}, "JU.Lst,JU.Lst,java.util.Map");
Clazz.defineMethod(c$, "getUVWs", 
function(mats, isUnique){
var uvws = (isUnique ? this.uniqueUVWs : this.uvws);
if (uvws != null) return uvws;
var list =  new JU.Lst();
for (var i = 0; i < mats.size(); i++) {
list.addLast(JS.SymmetryOperation.staticConvertOperation(null, mats.get(i), "uvw"));
}
if (isUnique) this.uniqueUVWs = list;
 else uvws = list;
return list;
}, "JU.Lst,~B");
Clazz.defineMethod(c$, "isUVW", 
function(uvw){
var uvws = this.getUVWs(this.getMatrices(true), true);
for (var i = 0, n = uvws.size(); i < n; i++) {
if (uvws.get(i).equals(uvw)) return true;
}
return false;
}, "~S");
/*eoif4*/})();
};
c$.bsUnique =  new java.util.Hashtable();
c$.typeNames =  Clazz.newArray(-1, ["plane", "proper axis", "improper axis", "center of inversion"]);
c$.mInv = JU.M3.newA9( Clazz.newFloatArray(-1, [-1, 0, 0, 0, -1, 0, 0, 0, -1]));
c$.axesMaxN =  Clazz.newIntArray(-1, [49, 0, 0, 1, 3, 1, 10, 1, 1, 0, 6, 0, 1, 0, 1, 0, 1, 0, 0, 0, 0, 0, 49, 10, 6, 6, 10, 1, 1]);
c$.nUnique =  Clazz.newIntArray(-1, [1, 0, 0, 2, 2, 4, 2, 1, 1, 0, 6, 0, 1, 0, 1, 0, 1, 0, 0, 0, 0, 0, 1, 2, 2, 4, 2, 6, 4]);
c$.maxAxis = JS.PointGroup.axesMaxN.length;
c$.SF2HM = ("Cn,1,2,3,4,5,6,7,8,9,10,11,12|Cnv,m,2m,3m,4mm,5m,6mm,7m,8mm,9m,10mm,11m,12mm,\u221em|Sn,,-1,-6,-4,(-10),-3,(-14),-8,(-18),-5,(-22),(-12)|Cnh,m,2/m,-6,4/m,-10,6/m,-14,8/m,-18,10/m,-22,12/m|Dn,,222,32,422,52,622,72,822,92,(10)22,(11)2,(12)22|Dnd,,-42m,-3m,-82m,-5m,(-12)2m,-7m,(-16)2m,-9m,(-20)2m,(-11)m,(-24)2m|Dnh,,mmm,-6m2,4/mmm,(-10)m2,6/mmm,(-14)m2,8/mmm,(-18)m2,10/mmm,(-22)m2,12/mmm,\u221e/mm|Ci,-1|Cs,m|T,23|Th,m-3|Td,-43m|O,432|Oh,m-3m").$plit("\\|");
c$.htSFToHM = null;
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
