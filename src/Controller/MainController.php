<?php

namespace App\Controller;

use App\Service\DatabaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MainController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(DatabaseService $db, SessionInterface $session): Response
    {
        $user = $this->currentUser($db, $session);
        if (!$user) return $this->redirectToRoute('app_login');

        return $this->render('home/index.html.twig', [
            'trajets' => $db->fetchAll("SELECT * FROM annoncecolistransport WHERE Statut='actif' ORDER BY ID DESC LIMIT 3"),
            'besoins' => $db->fetchAll("SELECT * FROM annoncebesointransport WHERE Statut='actif' ORDER BY ID DESC LIMIT 3"),
            'transactions' => $this->userTransactions($db, (int) $user['id']),
        ]);
    }

    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, DatabaseService $db, SessionInterface $session): Response
    {
        if ($this->currentUser($db, $session)) return $this->redirectToRoute('app_home');
        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email_connexion'));
            $password = (string) $request->request->get('motDePasse_connexion');
            $user = $db->fetchOne('SELECT * FROM utilisateur WHERE Email = ?', [$email]);
            if (!$user) return $this->render('auth/login.html.twig', ['error' => 'Utilisateur non trouve.']);
           $hash = (string) ($user['MotDePasse'] ?? '');

        if (!password_verify($password, $hash)) {
        return $this->render('auth/login.html.twig', ['error' => 'Mot de passe incorrect.']);
        }
            $session->set('user', ['id' => (int) $user['ID'], 'prenom' => $user['Prenom'], 'email' => $user['Email'], 'role' => $user['Role']]);
            return $this->redirectToRoute('app_home');
        }
        return $this->render('auth/login.html.twig');
    }

    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, DatabaseService $db): Response
    {
        if ($request->isMethod('GET')) {
            return $this->render('auth/register.html.twig');
        }

        $email = trim((string) $request->request->get('email'));
        $password = (string) $request->request->get('motDePasse');
        $confirm = (string) $request->request->get('confirmMotDePasse');
        $role = in_array($request->request->get('role'), ['expediteur', 'livreur'], true) ? $request->request->get('role') : 'expediteur';
        $values = [
            'nom' => trim((string) $request->request->get('nom')),
            'prenom' => trim((string) $request->request->get('prenom')),
            'email' => $email,
            'numeroTelephone' => trim((string) $request->request->get('numeroTelephone')),
            'adresse' => trim((string) $request->request->get('adresse')),
            'role' => $role,
        ];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->render('auth/register.html.twig', ['error' => 'Adresse email invalide.', 'values' => $values]);
        if (strlen($password) < 6) return $this->render('auth/register.html.twig', ['error' => 'Le mot de passe doit contenir au moins 6 caracteres.', 'values' => $values]);
        if ($password !== $confirm) return $this->render('auth/register.html.twig', ['error' => 'Les deux mots de passe ne correspondent pas.', 'values' => $values]);
        if ($db->fetchOne('SELECT ID FROM utilisateur WHERE Email = ?', [$email])) return $this->render('auth/register.html.twig', ['error' => 'Cet email existe deja.', 'values' => $values]);
        $db->insert('utilisateur', [
            'Nom' => $values['nom'],
            'Prenom' => $values['prenom'],
            'Email' => $email,
            'MotDePasse' => password_hash($password, PASSWORD_DEFAULT),
            'NumeroTelephone' => $values['numeroTelephone'],
            'Adresse' => $values['adresse'],
            'Role' => $role,
        ]);
        $this->addFlash('success', 'Compte cree. Tu peux maintenant te connecter.');
        return $this->redirectToRoute('app_login');
    }

    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(SessionInterface $session): RedirectResponse
    {
        $session->clear();
        return $this->redirectToRoute('app_login');
    }

    #[Route('/annonces/trajets', name: 'app_trajets')]
    public function trajets(Request $request, DatabaseService $db, SessionInterface $session): Response
    {
        $user = $this->requireRole($db, $session, 'expediteur');
        if (!$user) return $this->redirectToRoute('app_login');
        $sql = "SELECT a.*, u.Prenom, u.Nom FROM annoncecolistransport a LEFT JOIN utilisateur u ON u.ID=a.UtilisateurID WHERE a.Statut='actif'";
        $params = [];
        foreach (['depart' => 'a.PointDepart', 'destination' => 'a.Destination'] as $q => $col) {
            $v = trim((string) $request->query->get($q));
            if ($v !== '') { $sql .= " AND $col LIKE ?"; $params[] = "%$v%"; }
        }
        return $this->render('annonce/trajets.html.twig', ['annonces' => $db->fetchAll($sql . ' ORDER BY a.ID DESC', $params)]);
    }

    #[Route('/annonces/besoins', name: 'app_besoins')]
    public function besoins(Request $request, DatabaseService $db, SessionInterface $session): Response
    {
        $user = $this->requireRole($db, $session, 'livreur');
        if (!$user) return $this->redirectToRoute('app_login');
        $sql = "SELECT a.*, u.Prenom, u.Nom FROM annoncebesointransport a LEFT JOIN utilisateur u ON u.ID=a.UtilisateurID WHERE a.Statut='actif'";
        $params = [];
        foreach (['depart' => 'a.PointDepartSouhaite', 'destination' => 'a.DestinationSouhaitee'] as $q => $col) {
            $v = trim((string) $request->query->get($q));
            if ($v !== '') { $sql .= " AND $col LIKE ?"; $params[] = "%$v%"; }
        }
        return $this->render('annonce/besoins.html.twig', ['annonces' => $db->fetchAll($sql . ' ORDER BY a.ID DESC', $params)]);
    }

    #[Route('/mes-colis', name: 'app_my_besoins')]
    public function myBesoins(DatabaseService $db, SessionInterface $session): Response
    {
        $user = $this->requireRole($db, $session, 'expediteur');
        if (!$user) return $this->redirectToRoute('app_login');
        return $this->render('annonce/my_besoins.html.twig', [
            'annonces' => $db->fetchAll("SELECT a.*, EXISTS(SELECT 1 FROM transaction t WHERE t.AnnonceBesoinTransportID=a.ID AND t.StatutTransaction NOT IN ('annule','termine')) IsLocked FROM annoncebesointransport a WHERE a.UtilisateurID=? ORDER BY a.ID DESC", [$user['id']]),
        ]);
    }

    #[Route('/mes-trajets', name: 'app_my_trajets')]
    public function myTrajets(DatabaseService $db, SessionInterface $session): Response
    {
        $user = $this->requireRole($db, $session, 'livreur');
        if (!$user) return $this->redirectToRoute('app_login');
        return $this->render('annonce/my_trajets.html.twig', [
            'annonces' => $db->fetchAll("SELECT a.*, EXISTS(SELECT 1 FROM transaction t WHERE t.AnnonceColisTransportID=a.ID AND t.StatutTransaction NOT IN ('annule','termine')) IsLocked FROM annoncecolistransport a WHERE a.UtilisateurID=? ORDER BY a.ID DESC", [$user['id']]),
        ]);
    }

    #[Route('/annonces/trajets/nouveau', name: 'app_new_trajet', methods: ['GET', 'POST'])]
    #[Route('/mes-trajets/{id}/modifier', name: 'app_edit_trajet', methods: ['GET', 'POST'])]
    public function trajetForm(Request $request, DatabaseService $db, SessionInterface $session, ?int $id = null): Response
    {
        $user = $this->requireRole($db, $session, 'livreur');
        if (!$user) return $this->redirectToRoute('app_login');
        $values = [];
        if ($id) {
            if ($this->trajetIsLocked($db, $id)) { $this->addFlash('error', 'Trajet deja selectionne.'); return $this->redirectToRoute('app_my_trajets'); }
            $a = $db->fetchOne('SELECT * FROM annoncecolistransport WHERE ID=? AND UtilisateurID=?', [$id, $user['id']]);
            if (!$a) return $this->redirectToRoute('app_my_trajets');
            $values = ['point_depart'=>$a['PointDepart'],'destination'=>$a['Destination'],'description'=>$a['DescriptionColis'],'poids'=>$a['PoidsColis'],'date_depart'=>$a['DateDepart'],'date_arrivee_prevue'=>$a['DateArriveePrevue'],'prix'=>$a['PrixPropose'],'hauteur'=>$a['hauteur'],'largeur'=>$a['largeur'],'longueur'=>$a['longueur']];
        }
        if ($request->isMethod('POST')) {
            $values = $this->trajetData($request);
            $errors = $this->validateRequired($values, ['point_depart','destination','date_depart','prix']);
            if (!$errors) {
                $data = ['UtilisateurID'=>$user['id'],'PointDepart'=>$values['point_depart'],'Destination'=>$values['destination'],'DescriptionColis'=>$values['description'],'PoidsColis'=>$values['poids'],'DateDepart'=>$values['date_depart'],'DateArriveePrevue'=>$values['date_arrivee_prevue'] ?: null,'PrixPropose'=>$values['prix'],'Statut'=>'actif','hauteur'=>$values['hauteur'],'largeur'=>$values['largeur'],'longueur'=>$values['longueur']];
                $id ? $db->update('annoncecolistransport', $data, ['ID'=>$id]) : $db->insert('annoncecolistransport', $data);
                return $this->redirectToRoute('app_my_trajets');
            }
        }
        return $this->render('annonce/new_trajet.html.twig', ['errors'=>$errors ?? [], 'values'=>$values, 'is_edit'=>(bool) $id]);
    }

    #[Route('/annonces/besoins/nouveau', name: 'app_new_besoin', methods: ['GET', 'POST'])]
    #[Route('/mes-colis/{id}/modifier', name: 'app_edit_besoin', methods: ['GET', 'POST'])]
    public function besoinForm(Request $request, DatabaseService $db, SessionInterface $session, ?int $id = null): Response
    {
        $user = $this->requireRole($db, $session, 'expediteur');
        if (!$user) return $this->redirectToRoute('app_login');
        $values = [];
        if ($id) {
            if ($this->besoinIsLocked($db, $id)) { $this->addFlash('error', 'Colis deja accepte.'); return $this->redirectToRoute('app_my_besoins'); }
            $a = $db->fetchOne('SELECT * FROM annoncebesointransport WHERE ID=? AND UtilisateurID=?', [$id, $user['id']]);
            if (!$a) return $this->redirectToRoute('app_my_besoins');
            $values = ['point_depart'=>$a['PointDepartSouhaite'],'destination'=>$a['DestinationSouhaitee'],'description'=>$a['DescriptionColis'],'poids'=>$a['PoidsColis'],'date_limite_envoi'=>$a['DateLimiteEnvoi'],'budget'=>$a['Budget'],'nombres_de_colis'=>$a['nombres_de_colis'],'photo_colis'=>$a['PhotoColis'] ?? null];
        }
        if ($request->isMethod('POST')) {
            $values = $this->besoinData($request);
            $errors = $this->validateRequired($values, ['point_depart','destination','description','poids','date_limite_envoi','budget']);
            if (!$errors) {
                $data = ['UtilisateurID'=>$user['id'],'PointDepartSouhaite'=>$values['point_depart'],'DestinationSouhaitee'=>$values['destination'],'DescriptionColis'=>$values['description'],'PoidsColis'=>$values['poids'],'DateLimiteEnvoi'=>$values['date_limite_envoi'],'Budget'=>$values['budget'],'Statut'=>'actif','nombres_de_colis'=>$values['nombres_de_colis']];
                $photo = $this->saveColisPhoto($request);
                if ($photo) {
                    $data['PhotoColis'] = $photo;
                    $values['photo_colis'] = $photo;
                } elseif ($id && isset($a['PhotoColis'])) {
                    $values['photo_colis'] = $a['PhotoColis'];
                }
                $id ? $db->update('annoncebesointransport', $data, ['ID'=>$id]) : $db->insert('annoncebesointransport', $data);
                return $this->redirectToRoute('app_my_besoins');
            }
        }
        return $this->render('annonce/new_besoin.html.twig', ['errors'=>$errors ?? [], 'values'=>$values, 'is_edit'=>(bool) $id]);
    }

    #[Route('/annonces/trajets/{id}/choisir', name: 'app_choose_trajet', methods: ['POST'])]
    public function chooseTrajet(int $id, DatabaseService $db, SessionInterface $session): RedirectResponse
    {
        $user = $this->requireRole($db, $session, 'expediteur');
        if (!$user) return $this->redirectToRoute('app_login');
        $trajet = $db->fetchOne("SELECT * FROM annoncecolistransport WHERE ID=? AND Statut='actif'", [$id]);
        if (!$trajet || (int)$trajet['UtilisateurID'] === (int)$user['id']) return $this->redirectToRoute('app_trajets');
        $tid = $db->insert('transaction', ['AnnonceColisTransportID'=>$id,'UtilisateurID'=>$user['id'],'PrixConvenu'=>$trajet['PrixPropose'],'StatutTransaction'=>'en_attente_validation','livreur_id'=>$trajet['UtilisateurID']]);
        $this->notify($db, (int)$trajet['UtilisateurID'], 'Nouvelle demande de transport', 'Un expediteur a selectionne ton trajet.', $tid);
        return $this->redirectToRoute('app_transactions');
    }

    #[Route('/annonces/besoins/{id}/transporter', name: 'app_accept_besoin', methods: ['POST'])]
    public function acceptBesoin(int $id, DatabaseService $db, SessionInterface $session): RedirectResponse
    {
        $user = $this->requireRole($db, $session, 'livreur');
        if (!$user) return $this->redirectToRoute('app_login');
        $b = $db->fetchOne("SELECT * FROM annoncebesointransport WHERE ID=? AND Statut='actif'", [$id]);
        if (!$b || (int)$b['UtilisateurID'] === (int)$user['id']) return $this->redirectToRoute('app_besoins');
        $tid = $db->insert('transaction', ['AnnonceBesoinTransportID'=>$id,'UtilisateurID'=>$b['UtilisateurID'],'PrixConvenu'=>$b['Budget'],'StatutTransaction'=>'en_attente_paiement','livreur_id'=>$user['id']]);
        $db->update('annoncebesointransport', ['Statut'=>'en attente de payement','livreur_id'=>$user['id']], ['ID'=>$id]);
        $this->notify($db, (int)$b['UtilisateurID'], 'Colis accepte', 'Un transporteur a accepte ton colis. Tu peux payer.', $tid);
        return $this->redirectToRoute('app_transactions');
    }

    #[Route('/transactions', name: 'app_transactions')]
    public function transactions(DatabaseService $db, SessionInterface $session): Response
    {
        $user = $this->currentUser($db, $session);
        if (!$user) return $this->redirectToRoute('app_login');
        $transactions = $this->userTransactions($db, (int)$user['id']);
        $db->execute('UPDATE notification SET IsRead=1 WHERE UtilisateurID=?', [$user['id']]);
        return $this->render('transaction/index.html.twig', ['transactions'=>$transactions]);
    }

    #[Route('/transactions/{id}/message', name: 'app_send_message', methods: ['POST'])]
    public function sendMessage(int $id, Request $request, DatabaseService $db, SessionInterface $session): RedirectResponse
    {
        $u = $this->currentUser($db, $session); if (!$u) return $this->redirectToRoute('app_login');
        $t = $db->fetchOne('SELECT * FROM transaction WHERE ID=? AND (UtilisateurID=? OR livreur_id=?)', [$id, $u['id'], $u['id']]);
        if (!$t) return $this->redirectToRoute('app_transactions');
        $content = trim((string) $request->request->get('message'));
        if ($content !== '') {
            $db->insert('message', ['TransactionID'=>$id, 'SenderID'=>$u['id'], 'Contenu'=>$content]);
            $other = (int)$t['UtilisateurID'] === (int)$u['id'] ? (int)$t['livreur_id'] : (int)$t['UtilisateurID'];
            if ($other > 0) $this->notify($db, $other, 'Nouveau message', $u['prenom'].' a envoye un message sur cette livraison.', $id);
        }
        return $this->redirectToRoute('app_transactions');
    }

    #[Route('/notifications', name: 'app_notifications')]
    public function notifications(DatabaseService $db, SessionInterface $session): Response
    {
        $user = $this->currentUser($db, $session);
        if (!$user) return $this->redirectToRoute('app_login');
        return $this->redirectToRoute($user['role'] === 'livreur' ? 'app_transactions' : 'app_my_besoins');
    }

    #[Route('/transactions/{id}/valider', name: 'app_validate_transaction', methods: ['POST'])]
    public function validateTransaction(int $id, DatabaseService $db, SessionInterface $session): RedirectResponse
    {
        $u = $this->currentUser($db, $session); if (!$u) return $this->redirectToRoute('app_login');
        $t = $db->fetchOne("SELECT * FROM transaction WHERE ID=? AND livreur_id=? AND StatutTransaction='en_attente_validation'", [$id,$u['id']]);
        if ($t) { $db->update('transaction', ['StatutTransaction'=>'en_attente_paiement'], ['ID'=>$id]); $this->notify($db, (int)$t['UtilisateurID'], 'Transport valide', 'Le transporteur a valide ta demande. Tu peux payer.', $id); }
        return $this->redirectToRoute('app_transactions');
    }

    #[Route('/transactions/{id}/refuser', name: 'app_refuse_transaction', methods: ['POST'])]
    public function refuseTransaction(int $id, DatabaseService $db, SessionInterface $session): RedirectResponse
    {
        $u = $this->currentUser($db, $session); if (!$u) return $this->redirectToRoute('app_login');
        $t = $db->fetchOne("SELECT * FROM transaction WHERE ID=? AND livreur_id=? AND StatutTransaction='en_attente_validation'", [$id,$u['id']]);
        if ($t) { $db->update('transaction', ['StatutTransaction'=>'annule'], ['ID'=>$id]); $this->notify($db, (int)$t['UtilisateurID'], 'Demande refusee', 'Le transporteur a refuse ta demande.', $id); }
        return $this->redirectToRoute('app_transactions');
    }

    #[Route('/transactions/{id}/payer', name: 'app_pay_transaction', methods: ['POST'])]
    public function payTransaction(int $id, Request $request, DatabaseService $db, SessionInterface $session, HttpClientInterface $http): RedirectResponse
    {
        $u = $this->currentUser($db, $session); if (!$u) return $this->redirectToRoute('app_login');
        $t = $db->fetchOne("SELECT * FROM transaction WHERE ID=? AND UtilisateurID=? AND StatutTransaction='en_attente_paiement'", [$id,$u['id']]);
        if (!$t) return $this->redirectToRoute('app_transactions');
        $key = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: '';
        if ($key === '' || str_contains($key, 'remplacer')) { $this->addFlash('error', 'Le paiement en ligne n est pas encore configure.'); return $this->redirectToRoute('app_transactions'); }
        try {
            $r = $http->request('POST', 'https://api.stripe.com/v1/checkout/sessions', ['auth_bearer'=>$key, 'body'=>['mode'=>'payment','payment_method_types[0]'=>'card','success_url'=>$request->getSchemeAndHttpHost().$this->generateUrl('app_payment_success',['id'=>$id]).'?session_id={CHECKOUT_SESSION_ID}','cancel_url'=>$request->getSchemeAndHttpHost().$this->generateUrl('app_payment_cancel',['id'=>$id]),'line_items[0][quantity]'=>1,'line_items[0][price_data][currency]'=>'eur','line_items[0][price_data][unit_amount]'=>max(50,(int)round(((float)$t['PrixConvenu'])*100)),'line_items[0][price_data][product_data][name]'=>'Livraison de colis ColisComp']]);
            $checkout = $r->toArray(false);
        } catch (\Throwable) { $this->addFlash('error', 'Impossible de preparer le paiement en ligne.'); return $this->redirectToRoute('app_transactions'); }
        return isset($checkout['url']) ? $this->redirect($checkout['url']) : $this->redirectToRoute('app_transactions');
    }

    #[Route('/paiement/{id}/succes', name: 'app_payment_success')]
    public function paymentSuccess(int $id, Request $request, DatabaseService $db, SessionInterface $session, HttpClientInterface $http): RedirectResponse
    {
        $u = $this->currentUser($db, $session); if (!$u) return $this->redirectToRoute('app_login');
        $t = $db->fetchOne("SELECT * FROM transaction WHERE ID=? AND UtilisateurID=? AND StatutTransaction='en_attente_paiement'", [$id,$u['id']]);
        if (!$t) return $this->redirectToRoute('app_transactions');
        $key = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: ''; $sid = (string)$request->query->get('session_id','');
        try { $ok = ($http->request('GET', 'https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($sid), ['auth_bearer'=>$key])->toArray(false)['payment_status'] ?? '') === 'paid'; } catch (\Throwable) { $ok = false; }
        if ($ok) { $db->update('transaction', ['StatutTransaction'=>'paye'], ['ID'=>$id]); $this->notify($db, (int)$t['livreur_id'], 'Paiement recu', 'L expediteur a paye. Tu peux demarrer la livraison.', $id); }
        return $this->redirectToRoute('app_transactions');
    }

    #[Route('/paiement/{id}/annule', name: 'app_payment_cancel')]
    public function paymentCancel(): RedirectResponse { return $this->redirectToRoute('app_transactions'); }

    #[Route('/transactions/{id}/demarrer', name: 'app_start_delivery', methods: ['POST'])]
    public function startDelivery(int $id, DatabaseService $db, SessionInterface $session): RedirectResponse
    {
        $u = $this->currentUser($db, $session); if (!$u) return $this->redirectToRoute('app_login');
        $t = $db->fetchOne("SELECT * FROM transaction WHERE ID=? AND livreur_id=? AND StatutTransaction='paye'", [$id,$u['id']]);
        if ($t) { $db->update('transaction', ['StatutTransaction'=>'en_livraison'], ['ID'=>$id]); $this->notify($db, (int)$t['UtilisateurID'], 'Livraison demarree', 'Le transporteur a demarre la livraison.', $id); }
        return $this->redirectToRoute('app_transactions');
    }

    #[Route('/transactions/{id}/terminer', name: 'app_finish_delivery', methods: ['POST'])]
    public function finishDelivery(int $id, DatabaseService $db, SessionInterface $session): RedirectResponse
    {
        $u = $this->currentUser($db, $session); if (!$u) return $this->redirectToRoute('app_login');
        $t = $db->fetchOne("SELECT * FROM transaction WHERE ID=? AND livreur_id=? AND StatutTransaction='en_livraison'", [$id,$u['id']]);
        if ($t) { $db->update('transaction', ['StatutTransaction'=>'termine'], ['ID'=>$id]); $this->notify($db, (int)$t['UtilisateurID'], 'Livraison terminee', 'Le transporteur a marque la livraison comme terminee.', $id); }
        return $this->redirectToRoute('app_transactions');
    }

    #[Route('/tarifs', name: 'app_tarifs')]
    public function tarifs(DatabaseService $db, SessionInterface $session): Response
    {
        if (!$this->currentUser($db, $session)) return $this->redirectToRoute('app_login');
        return $this->render('tarif/index.html.twig');
    }

    private function requireRole(DatabaseService $db, SessionInterface $session, string $role): ?array
    {
        $u = $this->currentUser($db, $session);
        if (!$u) return null;
        if (($u['role'] ?? '') !== $role) { $this->addFlash('error', 'Acces non autorise.'); return null; }
        return $u;
    }

    private function currentUser(DatabaseService $db, SessionInterface $session): ?array
    {
        $u = $session->get('user'); if (!$u || !isset($u['id'])) return null;
        $row = $db->fetchOne('SELECT ID, Prenom, Email, Role FROM utilisateur WHERE ID=?', [$u['id']]);
        if (!$row) { $session->clear(); return null; }
        $fresh = ['id'=>(int)$row['ID'],'prenom'=>$row['Prenom'],'email'=>$row['Email'],'role'=>$row['Role'],'notification_count'=>$this->unreadNotificationCount($db,(int)$row['ID'])];
        $session->set('user', $fresh); return $fresh;
    }

    private function notify(DatabaseService $db, int $uid, string $title, string $message, ?int $tid = null): void
    {
        try { $db->insert('notification', ['UtilisateurID'=>$uid,'TransactionID'=>$tid,'Titre'=>$title,'Message'=>$message,'IsRead'=>0]); } catch (\Throwable) {}
    }
    private function readAndMarkNotifications(DatabaseService $db, int $uid, int $limit = 6): array
    {
        $items = $this->peekNotifications($db, $uid, $limit);
        try { $db->execute('UPDATE notification SET IsRead=1 WHERE UtilisateurID=?', [$uid]); } catch (\Throwable) {}
        return $items;
    }
    private function peekNotifications(DatabaseService $db, int $uid, int $limit = 6): array
    {
        try { return $db->fetchAll('SELECT * FROM notification WHERE UtilisateurID=? ORDER BY ID DESC LIMIT '.$limit, [$uid]); } catch (\Throwable) { return []; }
    }
    private function unreadNotificationCount(DatabaseService $db, int $uid): int
    {
        try { return (int)($db->fetchOne('SELECT COUNT(*) total FROM notification WHERE UtilisateurID=? AND IsRead=0', [$uid])['total'] ?? 0); } catch (\Throwable) { return 0; }
    }
    private function besoinIsLocked(DatabaseService $db, int $id): bool { return (bool)$db->fetchOne("SELECT ID FROM transaction WHERE AnnonceBesoinTransportID=? AND StatutTransaction NOT IN ('annule','termine') LIMIT 1", [$id]); }
    private function trajetIsLocked(DatabaseService $db, int $id): bool { return (bool)$db->fetchOne("SELECT ID FROM transaction WHERE AnnonceColisTransportID=? AND StatutTransaction NOT IN ('annule','termine') LIMIT 1", [$id]); }
    private function positiveDecimal(mixed $v): float { return max(0, (float)str_replace(',', '.', (string)$v)); }
    private function trajetData(Request $r): array { return ['point_depart'=>trim((string)$r->request->get('point_depart')),'destination'=>trim((string)$r->request->get('destination')),'description'=>trim((string)$r->request->get('description')),'poids'=>$this->positiveDecimal($r->request->get('poids')),'date_depart'=>(string)$r->request->get('date_depart'),'date_arrivee_prevue'=>(string)$r->request->get('date_arrivee_prevue'),'prix'=>$this->positiveDecimal($r->request->get('prix')),'hauteur'=>$this->positiveDecimal($r->request->get('hauteur')),'largeur'=>$this->positiveDecimal($r->request->get('largeur')),'longueur'=>$this->positiveDecimal($r->request->get('longueur'))]; }
    private function besoinData(Request $r): array { return ['point_depart'=>trim((string)$r->request->get('point_depart')),'destination'=>trim((string)$r->request->get('destination')),'description'=>trim((string)$r->request->get('description')),'poids'=>$this->positiveDecimal($r->request->get('poids')),'date_limite_envoi'=>(string)$r->request->get('date_limite_envoi'),'budget'=>$this->positiveDecimal($r->request->get('budget')),'nombres_de_colis'=>max(1,(int)$r->request->get('nombres_de_colis',1))]; }
    private function saveColisPhoto(Request $request): ?string
    {
        $file = $request->files->get('photo_colis');
        if (!$file instanceof UploadedFile || !$file->isValid()) return null;
        $extension = strtolower(pathinfo((string)$file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return null;
        $name = uniqid('colis_', true).'.'.$extension;
        $dir = $this->getParameter('kernel.project_dir').'/public/uploads/colis';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $file->move($dir, $name);
        return $name;
    }
    private function validateRequired(array $data, array $fields): array { $e=[]; foreach($fields as $f){ if(!isset($data[$f]) || $data[$f]==='' || $data[$f]===0.0) $e[]='Le champ '.str_replace('_',' ',$f).' est obligatoire.'; } return $e; }
    private function userTransactions(DatabaseService $db, int $uid): array
    {
        $transactions = $db->fetchAll("SELECT t.*, trajet.PointDepart TrajetDepart, trajet.Destination TrajetDestination, trajet.DescriptionColis TrajetDescription, besoin.PointDepartSouhaite BesoinDepart, besoin.DestinationSouhaitee BesoinDestination, besoin.DescriptionColis BesoinDescription, besoin.PhotoColis BesoinPhoto, expediteur.Prenom ExpediteurPrenom, livreur.Prenom LivreurPrenom FROM transaction t LEFT JOIN annoncecolistransport trajet ON trajet.ID=t.AnnonceColisTransportID LEFT JOIN annoncebesointransport besoin ON besoin.ID=t.AnnonceBesoinTransportID LEFT JOIN utilisateur expediteur ON expediteur.ID=t.UtilisateurID LEFT JOIN utilisateur livreur ON livreur.ID=t.livreur_id WHERE t.UtilisateurID=? OR t.livreur_id=? ORDER BY t.ID DESC", [$uid,$uid]);
        foreach ($transactions as &$transaction) {
            $transaction['notifications'] = $db->fetchAll('SELECT * FROM notification WHERE TransactionID=? AND UtilisateurID=? ORDER BY ID ASC', [$transaction['ID'], $uid]);
            $transaction['messages'] = $db->fetchAll('SELECT m.*, u.Prenom SenderPrenom FROM message m LEFT JOIN utilisateur u ON u.ID=m.SenderID WHERE m.TransactionID=? ORDER BY m.ID ASC', [$transaction['ID']]);
        }
        return $transactions;
    }
}
