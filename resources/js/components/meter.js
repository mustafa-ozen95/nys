/**
 * Doluluk çubuklarının genişliğini yazar.
 *
 * Genişlik satır içi style özniteliğiyle verilemiyor: CSP'de
 * style-src 'unsafe-inline' içermediği için tarayıcı özniteliği
 * düşürür ve çubuk hiç görünmez. Değer data-value'dan okunup
 * CSSOM üzerinden atanır; CSP betikten yapılan stil atamasını engellemez.
 */
export function init(root = document) {
    root.querySelectorAll('.meter__fill[data-value]').forEach((element) => {
        element.style.setProperty('--meter-value', `${element.dataset.value}%`);
    });
}
