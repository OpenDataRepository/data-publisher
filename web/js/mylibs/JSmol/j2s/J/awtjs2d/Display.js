Clazz.declarePackage("J.awtjs2d");
Clazz.load(null, "J.awtjs2d.Display", ["JU.PT"], function(){
var c$ = Clazz.declareType(J.awtjs2d, "Display", null);
c$.getFullScreenDimensions = Clazz.defineMethod(c$, "getFullScreenDimensions", 
function(canvas, widthHeight){
{
widthHeight[0] = canvas.width;
widthHeight[1] = canvas.height;
}}, "~O,~A");
c$.hasFocus = Clazz.defineMethod(c$, "hasFocus", 
function(canvas){
{
}return true;
}, "~O");
c$.requestFocusInWindow = Clazz.defineMethod(c$, "requestFocusInWindow", 
function(canvas){
{
}}, "~O");
c$.renderScreenImage = Clazz.defineMethod(c$, "renderScreenImage", 
function(vwr, g, size){
{
}}, "J.api.PlatformViewer,~O,~O");
c$.prompt = Clazz.defineMethod(c$, "prompt", 
function(vwr, label, data, list, asButtons){
if (!asButtons) {
{
var s = (data == null ? alert(label) : prompt(label, data));
return "" + s;
}}if (data != null) list = JU.PT.split(data, "|");
var option = (vwr).eval.promptAsync(label, list);
if (option != null) {
if (data == null) {
for (var i = 0; i < list.length; i++) if (list[i].equals(data)) return "" + i;

}return option;
}return "null";
}, "J.api.PlatformViewer,~S,~S,~A,~B");
c$.convertPointFromScreen = Clazz.defineMethod(c$, "convertPointFromScreen", 
function(canvas, ptTemp){
{
}}, "~O,JU.P3");
c$.drawImage = Clazz.defineMethod(c$, "drawImage", 
function(context, canvas, x, y, width, height, isDTI){
{
var buf8 = canvas.buf8;
var buf32 = canvas.buf32;
var n = canvas.width * canvas.height;
var di = 1;
if (isDTI) {
var diw = width % 2;
width = Math.floor(width/2);
di = Math.floor(canvas.width/width);
}
var dw = (canvas.width - width || x) * 4;
for (var i = 0, p = 0, j = x * 4; i < n;) {
buf8[j++] = (buf32[i] >> 16) & 0xFF;
buf8[j++] = (buf32[i] >> 8) & 0xFF;
buf8[j++] = buf32[i] & 0xFF;
buf8[j++] = 0xFF;
i += di;
if (++p%width==0) {
if (diw) {
i += 1;
buf8[j] = 0;
buf8[j+1] = 0;
buf8[j+2] = 0;
buf8[j+3] = 0;
}
j += dw;
}
}
context.putImageData(canvas.imgdata,0,0);
}}, "~O,~O,~N,~N,~N,~N,~B");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
