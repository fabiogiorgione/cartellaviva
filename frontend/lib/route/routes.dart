import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/auth_service.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/account/view/account_security_screen.dart';
import 'package:cartellaviva/features/account/view/anagrafica_screen.dart';
import 'package:cartellaviva/features/account/view/recupero_accesso_screen.dart';
import 'package:cartellaviva/features/account/view/two_factor_screen.dart';
import 'package:cartellaviva/features/portale/view/portale_screens.dart';
import 'package:cartellaviva/route/route_method.dart';
import 'package:cartellaviva/features/account/view/accept_invitation_screen.dart';
import 'package:cartellaviva/features/account/view/account_link_screens.dart';
import 'package:cartellaviva/features/account/view/forgot_password_screen.dart';
import 'package:cartellaviva/features/account/view/lock_screen.dart';
import 'package:cartellaviva/features/account/view/log_out_screen.dart';
import 'package:cartellaviva/features/account/view/login_screen.dart';
import 'package:cartellaviva/features/account/view/register_account_screen.dart';
import 'package:cartellaviva/features/famiglia/view/famiglia_dashboard_screen.dart';
import 'package:cartellaviva/features/documenti/view/analisi_documenti_screen.dart';
import 'package:cartellaviva/features/documenti/view/categorie_screen.dart';
import 'package:cartellaviva/features/documenti/view/documenti_screen.dart';
import 'package:cartellaviva/features/documenti/view/documento_screen.dart';
import 'package:cartellaviva/features/famiglia/view/famiglia_impostazioni_screen.dart';
import 'package:cartellaviva/features/import/view/import_esito_screen.dart';
import 'package:cartellaviva/features/portale/view/admin_enti_screen.dart';
import 'package:cartellaviva/features/ente/view/ente_gruppi_screen.dart';
import 'package:cartellaviva/features/ente/view/ente_iscritti_screen.dart';
import 'package:cartellaviva/features/ente/view/ente_nuova_richiesta_screen.dart';
import 'package:cartellaviva/features/ente/view/ente_profilo_screen.dart';
import 'package:cartellaviva/features/ente/view/ente_registrazione_screen.dart';
import 'package:cartellaviva/features/ente/view/ente_richiesta_screen.dart';
import 'package:cartellaviva/features/ente/view/ente_richieste_screen.dart';
import 'package:cartellaviva/features/richieste/view/invito_ente_screen.dart';
import 'package:cartellaviva/features/richieste/view/richiesta_screen.dart';
import 'package:cartellaviva/features/richieste/view/richieste_screen.dart';
import 'package:cartellaviva/features/richieste/view/risposta_link_screen.dart';
import 'package:cartellaviva/features/richieste/view/verifica_firma_screen.dart';
import 'package:cartellaviva/features/idee/view/idee_screen.dart';
import 'package:cartellaviva/features/documenti/view/condivisioni_screens.dart';
import 'package:cartellaviva/features/offline/view/offline_screen.dart';
import 'package:cartellaviva/features/emergenza/view/emergenza_screens.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:cartellaviva/features/ente/view/verifica_affiancata_screen.dart';
import 'package:cartellaviva/features/ente/view/registro_ente_screen.dart';
import 'package:cartellaviva/features/ente/view/ente_modelli_screen.dart';
import 'package:cartellaviva/features/documenti/view/nuovo_documento_screen.dart';
import 'package:cartellaviva/features/scadenze/view/scadenze_screen.dart';
import 'package:cartellaviva/features/pratiche/view/da_confermare_screen.dart';
import 'package:cartellaviva/features/pratiche/view/pagamenti_screen.dart';
import 'package:cartellaviva/features/pratiche/view/pratica_screen.dart';
import 'package:cartellaviva/features/pratiche/view/pratiche_screen.dart';
import 'package:cartellaviva/features/famiglia/view/benvenuto_screen.dart';
import 'package:cartellaviva/features/ente/view/ente_home_screen.dart';
import 'package:cartellaviva/features/famiglia/view/tenant_members_screen.dart';

/// Solo per la navigazione: senza sessione si torna al login ricordando la pagina.
/// La protezione vera è lato API (ogni endpoint verifica JWT e permessi).
class AuthMiddleware extends GetMiddleware {
  @override
  RouteSettings? redirect(String? route) {
    if (!AuthService.isLoggedIn) {
      final next = (route == null || route == '/') ? '' : '?next=${Uri.encodeComponent(route)}';
      return RouteSettings(name: '/auth/login$next');
    }
    final session = SessionService.to;
    final me = session.me;
    final target = route ?? '/';
    // Onboarding: anagrafica obbligatoria, tranne per inviti e richieste degli enti.
    if (me != null && !me.profileCompleted && !SessionService.isOnboardingExempt(target)) {
      return RouteSettings(name: '/account/profile');
    }
    // Accesso recuperato dal supporto: prima si riattiva la verifica in due passaggi.
    if (me != null && me.twoFactorObbligatoria && !target.startsWith('/account/')) {
      return RouteSettings(name: '/account/security');
    }
    // La home dipende dal tenant: nessuno -> benvenuto, ente -> area ente.
    if (me != null && (target == '/' || target == '/dashboard')) {
      final home = session.homeRoute();
      if (home != '/dashboard') {
        return RouteSettings(name: home);
      }
    }
    return null;
  }
}

/// Login e registrazione non hanno senso con una sessione attiva.
class GuestMiddleware extends GetMiddleware {
  @override
  RouteSettings? redirect(String? route) {
    return AuthService.isLoggedIn ? RouteSettings(name: '/dashboard') : null;
  }
}

List<GetPage> getPageRoute() => [
      GetPage(name: route.login, page: () => LoginScreen(), middlewares: [GuestMiddleware()]),
      GetPage(name: route.forgotPassword, page: () => ForgotPasswordScreen()),
      // Dai link delle email dell'account (?token=): pubbliche.
      GetPage(name: '/auth/reset-password', page: () => const ResetPasswordScreen()),
      GetPage(name: '/auth/attiva', page: () => const ResetPasswordScreen(attivazione: true)),
      GetPage(name: '/auth/verifica-email', page: () => const VerificaEmailScreen()),
      GetPage(name: '/auth/cambio-email', page: () => const CambioEmailScreen()),
      GetPage(name: route.createAccount, page: () => RegisterAccountScreen(), middlewares: [GuestMiddleware()]),
      GetPage(name: route.logOut, page: () => LogOutScreen()),
      // Pubblica: il token dell'invito fa da credenziale (gestisce anche l'utente già loggato).
      GetPage(name: route.acceptInvite, page: () => AcceptInvitationScreen()),
      // Secondo fattore: la challenge vive solo in memoria (SessionService.pendingChallenge).
      GetPage(name: route.twoFactor, page: () => TwoFactorScreen()),
      // Recupero dell'accesso e annullamento dal link dell'avviso: pubbliche.
      GetPage(name: '/auth/recupero', page: () => const RecuperoAccessoScreen()),
      GetPage(name: '/auth/recupero/conferma', page: () => const RecuperoAccessoScreen()),
      GetPage(name: '/procedure/annulla', page: () => const AnnullaProceduraScreen()),
      _authMiddleware('/admin', () => const PortaleSaluteScreen()),
      _authMiddleware('/admin/tenant', () => const PortaleTenantScreen()),
      _authMiddleware('/admin/utenti', () => const PortaleUtentiScreen()),
      _authMiddleware('/admin/recuperi', () => const PortaleProcedureScreen()),
      _authMiddleware('/admin/registro', () => const PortaleRegistroScreen()),
      _authMiddleware('/admin/team', () => const PortaleTeamScreen()),
      _authMiddleware('/admin/idee', () => const PortaleIdeeScreen()),
      _authMiddleware('/idee', () => const IdeeScreen()),
      _authMiddleware('/condivisioni', () => const CondivisioniScreen()),
      // Pubblica: link + codice di una condivisione esterna.
      GetPage(name: '/condivisione', page: () => const CondivisioneLinkScreen()),
      // Senza middleware: si apre anche senza rete o con la sessione scaduta (serve lo sblocco del telefono).
      GetPage(name: '/offline', page: () => const OfflineScreen()),
      // Accesso d'emergenza: invito (pubblico, poi accesso) e opposizione dal link dell'email (pubblica).
      GetPage(name: '/emergenza/accetta', page: () => const EmergenzaInvitoScreen()),
      GetPage(name: '/emergenza/annulla', page: () => const EmergenzaAnnullaScreen()),
      _authMiddleware('/emergenza', () => const EmergenzaMieScreen()),
      _authMiddleware('/aiuto', () => const AiutoScreen()),
      _authMiddleware('/aiuto/guida', () => const GuidaScreen()),
      _authMiddleware(route.accountProfile, () => AnagraficaScreen()),
      _authMiddleware(route.accountSecurity, () => AccountSecurityScreen()),
      _authMiddleware(route.lock, () => LockScreen()),
      _authMiddleware(route.tenantMembers, () => TenantMembersScreen()),
      _authMiddleware(route.nuovoDocumento, () => NuovoDocumentoScreen()),
      _authMiddleware(route.analisiDocumenti, () => const AnalisiDocumentiScreen()),
      _authMiddleware(route.benvenuto, () => BenvenutoScreen()),
      _authMiddleware(route.scadenze, () => ScadenzeScreen()),
      _authMiddleware(route.daConfermare, () => const DaConfermareScreen()),
      _authMiddleware(route.pratiche, () => const PraticheScreen()),
      _authMiddleware(route.pagamenti, () => const PagamentiScreen()),
      _authMiddleware(route.pratica, () => const PraticaScreen()),
      _authMiddleware(route.documenti, () => DocumentiScreen()),
      _authMiddleware(route.categorie, () => const CategorieScreen()),
      _authMiddleware(route.famigliaImpostazioni, () => FamigliaImpostazioniScreen()),
      _authMiddleware(route.importEsito, () => ImportEsitoScreen()),
      _authMiddleware(route.richieste, () => const RichiesteScreen()),
      _authMiddleware(route.richiesta, () => const RichiestaScreen()),
      // Pubblica: rifiuto e "non sono io" anche senza account (il token fa da credenziale).
      GetPage(name: route.invitoEnte, page: () => const InvitoEnteScreen()),
      // Risposta senza account: pubblica (link + codice email); il collegamento richiede l'account.
      GetPage(name: '/risposta', page: () => const RispostaLinkScreen()),
      // Pubblica: verifica di un'attestazione di firma con codice.
      GetPage(name: '/verifica-firma', page: () => const VerificaFirmaScreen()),
      _authMiddleware('/risposta/collega', () => const CollegaLinkScreen()),
      _authMiddleware('/ente/modelli', () => const EnteModelliScreen()),
      _authMiddleware('/ente/verifica', () => const VerificaAffiancataScreen()),
      _authMiddleware('/ente/registro', () => const RegistroEnteScreen()),
      _authMiddleware(route.enteRegistrazione, () => const EnteRegistrazioneScreen()),
      _authMiddleware(route.enteIscritti, () => const EnteIscrittiScreen()),
      _authMiddleware(route.enteGruppi, () => const EnteGruppiScreen()),
      _authMiddleware(route.enteRichiestaNuova, () => const EnteNuovaRichiestaScreen()),
      _authMiddleware(route.enteRichieste, () => const EnteRichiesteScreen()),
      _authMiddleware(route.enteRichiesta, () => const EnteRichiestaScreen()),
      _authMiddleware(route.enteProfilo, () => const EnteProfiloScreen()),
      _authMiddleware(route.adminEnti, () => const AdminEntiScreen()),
      _authMiddleware(route.ente, () => const EnteHomeScreen()),
      _authMiddleware(route.documento, () => DocumentoScreen()),
      _authMiddleware('/', () => FamigliaDashboardScreen()),
      _authMiddleware(route.dashboard, () => FamigliaDashboardScreen()),
    ];

GetPage _authMiddleware(String name, Widget Function() page) => GetPage(
    name: name,
    page: page,
    middlewares: [AuthMiddleware()],
    transition: Transition.noTransition);
