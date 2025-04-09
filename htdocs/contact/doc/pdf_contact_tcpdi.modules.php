<?php
/* Copyright (C) 2024 MDW <mdeweerd@users.noreply.github.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/tcpdidolibarr.class.php';

/**
 * Class to generate contact card PDF
 */
class pdf_contact_tcpdi extends DolibarrPdfTcpdi
{
    /**
     * @var Contact $object
     */
    public $object;

    /**
     * @var string $tPrefix  Prefix for translation keys
     */
    public $tPrefix = 'contactcard_';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        parent::__construct($db);

        // Set document properties
        $this->SetCreator('Dolibarr ' . DOL_VERSION);
        $this->SetAuthor('Dolibarr');
        $this->SetTitle('Contact Card');

        // Set default font
        $this->SetFont('helvetica', '', 10);

        // Set margins
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 15);
    }

    /**
     * Generate the PDF
     *
     * @param Contact $object Contact object
     * @param Translate $outputlangs Output language
     * @return void
     */
    public function generate($object, $outputlangs = null)
    {
        global $conf, $langs;

        $this->object = $object;
        if ($outputlangs === null) {
            $outputlangs = $langs;
        }
        $this->outputlangs = $outputlangs;

        // Initialize PDF
        try {
            $this->initGenerate($object, $outputlangs);
        }
        catch(Exception $e) {
            // TODO: pass down the error to the caller for logging (dol_syslog) and reporting
            //       (setEventMessage if the document was generated from the web interface)
            return;
        }

        // Add a new page
        $this->AddPage();

        // Add logo and title
        $this->ClientLogoTitle();

        $this->writeHTMLFromTpl('contact_card');
        // Add contact information
        // $this->addContactInfo();

        // Add company information if available
        if ($object->socid > 0) {
            // $this->addCompanyInfo();
        }

        // Add notes if available
        if (!empty($object->note_public)) {
            // $this->addNotes();
        }

        // Output the PDF
        $this->Output($this->filePath, 'F');
    }

    /**
     * Overloaded to return the name of the contact rather than a reference or an ID
     */
    public function getObjectName()
    {
        /** @var Contact $this->object */
        return $this->object->getFullName($this->outputlangs);
    }

    /**
     * Overloaded because documents related to contacts are located in a subdirectory of third party
     */
    public function getObjectOutputDir()
    {
        global $conf;
        return  $conf->societe->multidir_output[$this->object->entity].'/contact/'.dol_sanitizeFileName($this->object->ref);
    }
} 