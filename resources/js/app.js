import Chart from 'chart.js/auto';

import * as addressAssignment from './components/address-assignment.js';
import * as aggregationForm from './components/aggregation-form.js';
import * as bgpForm from './components/bgp-form.js';
import * as bildirim from './components/bildirim.js';
import * as bgpReceived from './components/bgp-received.js';
import * as chart from './components/chart.js';
import * as columns from './components/columns.js';
import * as confirmDialog from './components/confirm.js';
import * as copy from './components/copy.js';
import * as dondurmeIlerleme from './components/dondurme-ilerleme.js';
import * as dialog from './components/dialog.js';
import * as firewallForm from './components/firewall-form.js';
import * as ipAddressForm from './components/ip-address-form.js';
import * as secim from './components/secim.js';
import * as keyboard from './components/keyboard.js';
import * as kullaniciMenu from './components/kullanici-menu.js';
import * as meter from './components/meter.js';
import * as navigation from './components/navigation.js';
import * as oturumKilidi from './components/oturum-kilidi.js';
import * as portForm from './components/port-form.js';
import * as provisioningForm from './components/provisioning-form.js';
import * as remoteSession from './components/remote-session.js';
import * as routeForm from './components/route-form.js';
import * as secretReveal from './components/secret-reveal.js';
import * as selectAll from './components/select-all.js';
import * as slotPicker from './components/slot-picker.js';
import * as termOrder from './components/term-order.js';
import * as theme from './components/theme.js';
import * as toast from './components/toast.js';
import * as writeJob from './components/write-job.js';

/**
 * Uygulama girişi.
 *
 * Buradaki modüller yalnızca DOM, olay ve arayüz işleriyle ilgilenir.
 * Hesaplama ve doğrulama sunucuda yapılır.
 *
 * Sayfa geçişleri tarayıcıyı YENİLEMİYOR (bkz. navigation.js): gövde yerinde
 * değişiyor ve aşağıdaki kurulum her geçişte yeniden koşuyor. Bunun güvenli
 * olmasının sebebi delegasyonun tekrara dayanıklı olması (lib/dom.js `on`);
 * düğüme doğrudan bağlanan ya da zamanlayıcı kuran birkaç bileşen kendi
 * içinde ayrıca korunuyor.
 *
 * navigation EN SONA kurulur: bağlantıyı önce pencere ve onay bileşenleri
 * görmeli, geçiş yalnızca onlar karışmadıysa devralınmalı.
 */
window.Chart = Chart;

theme.init();

function herCizimde() {
    dialog.init();
    addressAssignment.init();
    aggregationForm.init();
    bgpForm.init();
    bgpReceived.init();
    bildirim.init();
    confirmDialog.init();
    copy.init();
    dondurmeIlerleme.init();
    firewallForm.init();
    chart.init();
    columns.init();
    remoteSession.init();
    routeForm.init();
    secretReveal.init();
    selectAll.init();
    slotPicker.init();
    ipAddressForm.init();
    secim.init();
    keyboard.init();
    kullaniciMenu.init();
    meter.init();
    portForm.init();
    portForm.initNetconf();
    provisioningForm.init();
    termOrder.init();
    toast.init();
    writeJob.init();
}

document.addEventListener('DOMContentLoaded', () => {
    herCizimde();
    navigation.init();
    /* Ekran kilidi: hareketsiz sekme oturumu kapatır (bkz. oturum-kilidi.js). Bir kez. */
    oturumKilidi.init();
});

document.addEventListener(navigation.OLAY, herCizimde);
