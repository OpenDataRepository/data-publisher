Clazz.declarePackage("javajs.img");
Clazz.load(["javajs.img.CRCEncoder", "java.util.ArrayList"], "javajs.img.PngEncoder", ["java.io.ByteArrayOutputStream", "java.util.zip.Deflater", "$.DeflaterOutputStream"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.png = null;
this.encodeAlpha = false;
this.filter = 0;
this.bytesPerPixel = 0;
this.compressionLevel = 0;
this.transparentColor = null;
this.comment = null;
if (!Clazz.isClassDefined("javajs.img.PngEncoder.PNG")) {
javajs.img.PngEncoder.$PngEncoder$PNG$ ();
}
if (!Clazz.isClassDefined("javajs.img.PngEncoder.Chunk")) {
javajs.img.PngEncoder.$PngEncoder$Chunk$ ();
}
this.scanLines = null;
this.byteWidth = 0;
Clazz.instantialize(this, arguments);}, javajs.img, "PngEncoder", javajs.img.CRCEncoder);
Clazz.overrideMethod(c$, "setParams", 
function(params){
if (this.quality < 0) {
this.quality = (params.containsKey("qualityPNG") ? (params.get("qualityPNG")).intValue() : 2);
} else if (this.quality > 9 && this.quality < 90) {
this.quality = 9;
}this.dpi = 300;
if (this.quality >= 90) {
this.dpi = this.quality;
this.quality = 2;
}this.encodeAlpha = false;
this.filter = 0;
this.compressionLevel = this.quality;
this.transparentColor = params.get("transparentColor");
this.comment = params.get("comment");
var type = (params.get("type") + "0000").substring(0, 4);
var appPrefix = params.get("pngAppPrefix");
this.png = Clazz.innerTypeInstance(javajs.img.PngEncoder.PNG, this, null, type, appPrefix);
this.png.bytes = params.get("pngImgData");
this.png.appData = params.get("pngAppData");
}, "java.util.Map");
Clazz.overrideMethod(c$, "generate", 
function(){
var ok;
try {
ok = (this.png.bytes == null ? this.pngEncode() : this.png.readDataFromBytes() > 0);
if (ok) {
this.writeBytes(javajs.img.PngEncoder.pngIdBytes);
this.png.writePNGData();
var b = this.getBytes();
this.out.write(b, 0, b.length);
}} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
ok = false;
} else {
throw e;
}
}
if (!ok) {
this.out.cancel();
}});
Clazz.defineMethod(c$, "pngEncode", 
function(){
this.addHeader();
this.addText("Software\0" + this.comment);
this.addText("Creation Time\0" + this.date);
if (this.dpi > 0) this.addPhysicalSize();
if (!this.encodeAlpha && this.transparentColor != null) this.addTransparentColor(this.transparentColor.intValue());
if (!this.addImageData()) return false;
this.addEnd();
return true;
});
Clazz.defineMethod(c$, "addHeader", 
function(){
var c = Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, "IHDR",  Clazz.newByteArray (13, 0));
c.addInt4(this.width);
c.addInt4(this.height);
c.addByte(8);
c.addByte(this.encodeAlpha ? 6 : 2);
c.addByte(0);
c.addByte(0);
c.addByte(0);
this.png.addChunk(c);
});
Clazz.defineMethod(c$, "addText", 
function(msg){
this.png.addChunk(Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, "tEXt", msg));
}, "~S");
Clazz.defineMethod(c$, "addTransparentColor", 
function(icolor){
var c = Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, "tRNS",  Clazz.newByteArray (6, 0));
c.addInt2((icolor >> 16) & 0xFF);
c.addInt2((icolor >> 8) & 0xFF);
c.addInt2(icolor & 0xFF);
this.png.addChunk(c);
}, "~N");
Clazz.defineMethod(c$, "addPhysicalSize", 
function(){
var c = Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, "pHYs",  Clazz.newByteArray (9, 0));
var ppm = Math.round(39.3700787 * this.dpi);
c.addInt4(ppm);
c.addInt4(ppm);
c.addByte(0);
this.png.addChunk(c);
});
Clazz.defineMethod(c$, "addImageData", 
function(){
this.bytesPerPixel = (this.encodeAlpha ? 4 : 3);
this.byteWidth = this.width * this.bytesPerPixel;
var scanWidth = this.byteWidth + 1;
var rowsLeft = this.height;
var nRows;
var scanPos;
var deflater =  new java.util.zip.Deflater(this.compressionLevel);
var outBytes =  new java.io.ByteArrayOutputStream(1024);
var compBytes =  new java.util.zip.DeflaterOutputStream(outBytes, deflater);
var pt = 0;
try {
while (rowsLeft > 0) {
nRows = Math.max(1, Math.min(Clazz.doubleToInt(32767 / scanWidth), rowsLeft));
this.scanLines =  Clazz.newByteArray (scanWidth * nRows, 0);
var nPixels = this.width * nRows;
scanPos = 0;
for (var i = 0; i < nPixels; i++, pt++) {
if (i % this.width == 0) {
this.scanLines[scanPos++] = this.filter;
}this.scanLines[scanPos++] = ((this.pixels[pt] >> 16) & 0xff);
this.scanLines[scanPos++] = ((this.pixels[pt] >> 8) & 0xff);
this.scanLines[scanPos++] = ((this.pixels[pt]) & 0xff);
if (this.encodeAlpha) {
this.scanLines[scanPos++] = ((this.pixels[pt] >> 24) & 0xff);
}}
compBytes.write(this.scanLines, 0, scanPos);
rowsLeft -= nRows;
}
compBytes.close();
var compressedLines = outBytes.toByteArray();
deflater.finish();
this.png.addChunk(Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, "IDAT", compressedLines));
return true;
} catch (e) {
if (Clazz.exceptionOf(e,"java.io.IOException")){
System.err.println(e.toString());
return false;
} else {
throw e;
}
}
});
Clazz.defineMethod(c$, "addEnd", 
function(){
this.png.addChunk(Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, "IEND",  Clazz.newByteArray (0, 0)));
});
c$.$PngEncoder$PNG$ = function(){
/*if4*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.type = null;
this.appPrefix = null;
this.appData = null;
this.bytes = null;
this.dataPt = 0;
this.data = null;
this.textPt = 0;
this.isValid = false;
Clazz.instantialize(this, arguments);}, javajs.img.PngEncoder, "PNG", null);
Clazz.prepareFields (c$, function(){
this.data =  new java.util.ArrayList();
});
Clazz.makeConstructor(c$, 
function(type, appPrefix){
this.type = type;
if (appPrefix == null) appPrefix = "#SwingJS.";
if (appPrefix.length < 9) appPrefix = (appPrefix + ".........");
if (appPrefix.length > 9) appPrefix = appPrefix.substring(0, 9);
this.appPrefix = appPrefix;
}, "~S,~S");
Clazz.defineMethod(c$, "addChunk", 
function(c){
if (this.textPt <= 0 && c.name.equals("tEXt")) this.textPt = this.data.size();
if (!this.isValid && c.name.equals("IDAT")) this.isValid = true;
this.data.add(c);
}, "javajs.img.PngEncoder.Chunk");
Clazz.defineMethod(c$, "writePNGData", 
function(){
if (this.appData != null) {
this.setJmolTypeText(0, 0);
this.dataPt = javajs.img.PngEncoder.pngIdBytes.length;
var last = this.data.get(this.data.size() - 1);
if (last.name == null) this.data.remove(this.data.size() - 1);
for (var i = 0, n = this.data.size(); i < n; i++) {
this.dataPt += this.data.get(i).getWritelength();
}
this.data.add(Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, null, this.appData));
this.setJmolTypeText(this.dataPt, this.appData.length);
}for (var i = 0, n = this.data.size(); i < n; i++) {
this.data.get(i).write();
}
});
Clazz.defineMethod(c$, "getApplicationText", 
function(nPNG, nData){
var sPNG = "000000000" + nPNG;
sPNG = sPNG.substring(sPNG.length - 9);
var sData = "000000000" + nData;
sData = sData.substring(sData.length - 9);
return this.appPrefix + "\0" + this.type + sPNG + "+" + sData;
}, "~N,~N");
Clazz.defineMethod(c$, "setJmolTypeText", 
function(nPNG, nState){
var s = this.getApplicationText(nPNG, nState);
var test = this.appPrefix.getBytes();
var c = Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, "tEXt", s);
if (this.textPt == 1 && this.data.get(1).startsWith(test)) {
this.data.remove(1);
}this.data.add(1, c);
this.textPt = 1;
}, "~N,~N");
Clazz.defineMethod(c$, "readDataFromBytes", 
function(){
for (var i = javajs.img.PngEncoder.pngIdBytes.length; --i >= 0; ) if (this.bytes[i] != javajs.img.PngEncoder.pngIdBytes[i]) return -1;

this.dataPt = javajs.img.PngEncoder.pngIdBytes.length;
while (this.dataPt < this.bytes.length) {
if (!this.readDataChunk()) break;
}
if (!this.isValid) return -1;
if (this.dataPt < this.bytes.length) {
var extra =  Clazz.newByteArray (this.bytes.length - this.dataPt, 0);
System.arraycopy(this.bytes, this.dataPt, extra, 0, extra.length);
this.data.add(Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, null, extra));
}return this.bytes.length;
});
Clazz.defineMethod(c$, "readDataChunk", 
function(){
var n = this.readInt4();
var b =  Clazz.newByteArray (n, 0);
var name =  String.instantialize(this.readBytes(this.b$["javajs.img.PngEncoder"].int4));
this.readBytes(b);
this.addChunk(Clazz.innerTypeInstance(javajs.img.PngEncoder.Chunk, this, null, name, b));
this.dataPt += 4;
return (n > 0 || !name.equals("IEND"));
});
Clazz.defineMethod(c$, "readInt4", 
function(){
var j = this.dataPt;
var n = (this.bytes[j + 3] & 0xff) | (this.bytes[j + 2] & 0xff) << 8 | (this.bytes[j + 1] & 0xff) << 16 | (this.bytes[j] & 0xff) << 24;
this.dataPt += 4;
return n | 0;
});
Clazz.defineMethod(c$, "readBytes", 
function(b){
System.arraycopy(this.bytes, this.dataPt, b, 0, b.length);
this.dataPt += b.length;
return b;
}, "~A");
/*eoif4*/})();
};
c$.$PngEncoder$Chunk$ = function(){
/*if4*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.name = null;
this.bytes = null;
this.len = 0;
this.pt = 0;
this.text = null;
Clazz.instantialize(this, arguments);}, javajs.img.PngEncoder, "Chunk", null);
Clazz.makeConstructor(c$, 
function(name, text){
this.construct (name, text.getBytes());
this.text = text;
}, "~S,~S");
Clazz.makeConstructor(c$, 
function(name, bytes){
this.name = name;
this.bytes = bytes;
this.len = bytes.length;
}, "~S,~A");
Clazz.defineMethod(c$, "write", 
function(){
if (this.name == null) {
this.b$["javajs.img.PngEncoder"].writeBytes(this.bytes);
} else {
this.b$["javajs.img.PngEncoder"].writeInt4(this.len);
this.b$["javajs.img.PngEncoder"].startPos = this.b$["javajs.img.PngEncoder"].bytePos;
this.b$["javajs.img.PngEncoder"].writeString(this.name);
this.b$["javajs.img.PngEncoder"].writeBytes(this.bytes);
this.b$["javajs.img.PngEncoder"].writeCRC();
}return this.len;
});
Clazz.defineMethod(c$, "addByte", 
function(i){
this.bytes[this.pt++] = i;
}, "~N");
Clazz.defineMethod(c$, "addInt2", 
function(n){
javajs.img.CRCEncoder.getInt2(n, this.bytes, this.pt);
this.pt += 2;
}, "~N");
Clazz.defineMethod(c$, "addInt4", 
function(n){
javajs.img.CRCEncoder.getInt4(n, this.bytes, this.pt);
this.pt += 4;
}, "~N");
Clazz.defineMethod(c$, "getWritelength", 
function(){
return this.len + 12;
});
Clazz.defineMethod(c$, "startsWith", 
function(test){
if (this.bytes.length < test.length) return false;
for (var i = test.length; --i >= 0; ) if (this.bytes[i] != test[i]) {
return false;
}
return true;
}, "~A");
Clazz.overrideMethod(c$, "toString", 
function(){
return "[Chunk " + this.name + " " + this.len + " " + (this.text == null ? "" : this.text) + "]";
});
/*eoif4*/})();
};
c$.pngIdBytes =  Clazz.newByteArray(-1, [-119, 80, 78, 71, 13, 10, 26, 10]);
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
