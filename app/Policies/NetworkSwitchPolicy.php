<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NetworkSwitch;
use App\Models\User;

class NetworkSwitchPolicy extends BaseResourcePolicy
{
    protected function resource(): string
    {
        return 'switches';
    }

    /**
     * Uzak oturum (SSH) açma yetkisi.
     *
     * Görüntüleme yetkisinden ayrıdır: kullanıcı switchi listede görebilir ama
     * terminal açamayabilir. Ayrıca kimlik bilgisi tanımlı değilse bağlantı
     * kurulamayacağı için erken reddedilir.
     */
    public function connect(User $user, NetworkSwitch $switch): bool
    {
        return $this->permitsConnect($user, $switch)
            && $switch->sshCredential !== null;
    }

    /**
     * Cihazın KENDİ ayarlarına yazma yetkisi: SNMP adı, yapılandırma yedeği.
     *
     * "update" envanter kaydını düzenlemektir; bu ise sahadaki cihaza commit
     * atmaktır. İkisi bilinçli olarak ayrıdır — kaydı düzeltebilen biri,
     * cihazın yapılandırmasını değiştirebilecek biri değildir.
     *
     * Port, VLAN, route, firewall, politika, BGP ve toplama arayüzleri bu
     * yetkiye BAĞLI DEĞİLDİR; her biri kendi kaynağıdır ve kendi
     * görüntüle/oluştur/düzenle/sil yetkilerini taşır.
     *
     * NETCONF kimliği tanımlı değilse yazma yolu yoktur ve düğme gösterilmez.
     */
    public function configure(User $user, NetworkSwitch $switch): bool
    {
        return $this->permitsPrivileged($user, 'configure', $switch)
            && $switch->netconfCredential !== null;
    }

    /**
     * Cihaz erişim parolasını EKRANDA GÖRME yetkisi.
     *
     * "configure"dan ayrıdır: cihazı yapılandırabilen biri, parolayı
     * okuyabilecek biri değildir. Parola cihaza konsoldan ve SSH'tan da
     * girer; görülmesi paneli aşan bir erişimdir.
     *
     * Gate::before tarafından BYPASS EDİLMEZ (permitsPrivileged): yönetici
     * geçer ama yetki satırı yoksa başkası geçemez.
     *
     * Kimlik tanımlı değilse gösterilecek bir şey yoktur.
     */
    public function secret(User $user, NetworkSwitch $switch): bool
    {
        return $this->permitsPrivileged($user, 'secret', $switch)
            && ($switch->netconfCredential !== null || $switch->sshCredential !== null);
    }

    /**
     * Cihaz erişim parolasını DEĞİŞTİRME yetkisi.
     *
     * Görme yetkisinden ayrıdır ve daha ağırdır: yanlış giderse switch
     * panelden yönetilemez hâle gelir. NETCONF kimliği olmadan tur
     * başlatılamaz.
     */
    public function rotate(User $user, NetworkSwitch $switch): bool
    {
        return $this->permitsPrivileged($user, 'rotate', $switch)
            && $switch->netconfCredential !== null;
    }

    /**
     * Cihaza TAM config yükleme yetkisi.
     *
     * Panelin en geniş etkili yazma yoludur: "tamamen değiştir" seçeneğiyle
     * cihazdaki yapılandırmanın tamamı silinip yerine gönderilen metin
     * konur. "configure" bunun için YETMEZ — tek bir portu değiştirmekle
     * cihazı sıfırdan kurmak aynı şey değildir.
     *
     * NETCONF kimliği tanımlı değilse yazma yolu yoktur.
     */
    public function provision(User $user, NetworkSwitch $switch): bool
    {
        return $this->permitsPrivileged($user, 'provision', $switch)
            && $switch->netconfCredential !== null;
    }
}
